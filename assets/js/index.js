document.addEventListener('DOMContentLoaded', () => {
	/* -------------------- 数据与状态区 -------------------- */
	// 后端原始数据
	let monitorData = { nodes: [] };
	let webInfoData;
	let outagesData = { outages: [] };
	// 当前视图按钮
	let activeButton = document.querySelector('.controls button[data-view="map"]');
	// 当前语言
	let currentLang = 'zh';
	// 已选标签
	let activeTags = new Set();
	// 卡片分页当前页
	let currentPage = 1;
	// 每页卡片数
	const itemsPerPage = 18;

	/* 多语言文案 – 键名即 data-lang 属性值 */
	const translations = {
		en: {
			title: 'Pinty Monitor', btn_map: 'Global Map', btn_standalones: 'Server Cards', btn_outages: 'Recent Outages',
			footer_suffix: 'Updates every 10 seconds.', error_loading: 'Failed to load data:', error_api_status: 'API returned status',
			error_api_error: 'API Error:', stat_cpu: 'CPU', stat_mem: 'Memory', stat_disk: 'Disk', stat_net: 'Network',
			stat_traffic: 'Traffic', stat_load: 'Load', stat_uptime: 'Uptime', modal_intro: 'Introduction', modal_price: 'Price',
			price_yearly: 'Yearly', modal_cpu_chart: 'CPU Usage (%)', modal_mem_chart: 'Memory Usage (%)', modal_load_chart: 'System Load',
			modal_net_chart: 'Network Speed (KB/s)', modal_proc_chart: 'Processes', modal_conn_chart: 'Connections',
			outages_none: 'No outages recorded.', outages_recovered: 'Recovered, duration approx.', outages_minutes: 'minutes.',
			outages_seconds: 'seconds', outages_hours: 'hours', outages_days: 'days', tooltip_anomaly: 'Status is anomalous',
			map_anomaly: 'Server Offline', no_data_available: 'No data available', filter_by: 'Filter:', filter_all: 'All',
			info_system: 'System', info_arch: 'Architecture', info_cpu: 'CPU Model', info_ram: 'RAM Size',
			info_disk: 'Disk Size', info_outage: 'Outage Duration', loading_charts: 'Loading charts...'
		},
		zh: {
			title: 'Pinty 监控', btn_map: '全球地图', btn_standalones: '独立服务器视图', btn_outages: '最近的掉线记录',
			footer_suffix: '每10秒更新.', error_loading: '无法加载数据:', error_api_status: 'API 返回状态', error_api_error: 'API 错误:',
			stat_cpu: 'CPU', stat_mem: '内存', stat_disk: '硬盘', stat_net: '网络', stat_traffic: '流量', stat_load: '负载', stat_uptime: '在线',
			modal_intro: '简介', modal_price: '价格', price_yearly: '年付', modal_cpu_chart: 'CPU 使用率 (%)',
			modal_mem_chart: '内存使用率 (%)', modal_load_chart: '系统负载', modal_net_chart: '网络速度 (KB/s)',
			modal_proc_chart: '进程数', modal_conn_chart: '连接数', outages_none: '没有掉线记录。',
			outages_recovered: '已恢复，持续时间约', outages_minutes: '分钟。', outages_seconds: '秒',
			outages_hours: '小时', outages_days: '天', tooltip_anomaly: '状态异常', map_anomaly: '服务器掉线',
			no_data_available: '无可用数据', filter_by: '筛选:', filter_all: '全部', info_system: '操作系统',
			info_arch: '架构', info_cpu: 'CPU 型号', info_ram: '内存大小', info_disk: '磁盘大小', info_outage: '掉线时长',
			loading_charts: '图表加载中...'
		}
	};

	/* -------------------- DOM 缓存区 -------------------- */
	const tooltip = document.getElementById('tooltip');
	// 地图
	const mapContainer = document.getElementById('world-map-container');
	// 页面选择按钮
	const controls = document.querySelector('.controls');
	// 3个主要页面
	const views = document.querySelectorAll('.view');
	// 节点信息页面卡片
	const modalOverlay = document.getElementById('details-modal');
	// 节点信息页面卡片的关闭按钮
	const modalCloseBtn = document.getElementById('modal-close-btn');
	// 语言按钮列表
	const langSelector = document.querySelector('.language-selector');

	/* =========================================================
	 * 工具函数集合
	 * ========================================================= */
	/** 切换界面语言 – 同步更新 DOM、html lang 属性、localStorage */
	function setLanguage(lang) {
		currentLang = lang;
		document.querySelectorAll('[data-lang]').forEach(element => {
			const key = element.getAttribute('data-lang');
			if (translations[lang][key]) element.textContent = translations[lang][key];
		});
		document.documentElement.lang = lang;
		localStorage.setItem('pintyLang', lang);
		langSelector.querySelectorAll('a').forEach(a => a.classList.toggle('active', a.dataset.langCode === lang));
		if (monitorData.nodes.length > 0) renderAllViews();
	}

	/** 统一错误提示 – 生成浮层并写入错误信息 */
	function displayError(message) {
		const main = document.getElementById('main-content');
		let errorOverlay = document.getElementById('error-overlay');
		if (!errorOverlay) {
			errorOverlay = document.createElement('div');
			errorOverlay.id = 'error-overlay';
			errorOverlay.className = 'error-overlay';
			main.appendChild(errorOverlay);
		}
		errorOverlay.innerHTML = `<pre><strong>${translations[currentLang].error_loading}</strong>\n${message}</pre>`;
	}

	/** 请求服务器列表数据 */
	async function fetchServerList() {
		try {
			const response = await fetch('api.php?action=list');
			if (!response.ok) throw new Error(`${translations[currentLang].error_api_status} ${response.status}: ${await response.text()}`);
			const data = await response.json();
			if (data.error) throw new Error(`${translations[currentLang].error_api_error} ${data.error}`);
			
			monitorData = data;
			renderAllViews();
		} catch (error) {
			console.error("获取监控数据失败: ", error);
			displayError(error.message);
		}
	}

	/** 请求网页设置信息 */
	async function fetchWebInfoData() {
		try {
			const response = await fetch('api.php?action=web-info');
			if (!response.ok) throw new Error(`${translations[currentLang].error_api_status} ${response.status}: ${await response.text()}`);
			const data = await response.json();
			if (data.error) throw new Error(`${translations[currentLang].error_api_error} ${data.error}`);
			
			webInfoData = data;
			if (webInfoData.site_name) {
				document.title = webInfoData.site_name;
				document.getElementById('site-title').textContent = webInfoData.site_name;
				document.getElementById('copyright-footer').textContent = `Copyright 2025 ${webInfoData.site_name}. Powered by Pinty.`;
			}
		} catch (error) {
			console.error("获取设置信息失败: ", error);
			displayError(error.message);
		}
	}

	async function fetchOutageList() {
		console.log('fetchOutageList');
		try {
			const response = await fetch('api.php?action=outages');
			if (!response.ok) throw new Error(`${translations[currentLang].error_api_status} ${response.status}: ${await response.text()}`);
			const data = await response.json();
			if (data.error) throw new Error(`${translations[currentLang].error_api_error} ${data.error}`);

			outagesData = data;
			renderAllViews();
		} catch (error) {
			console.error("获取掉线记录列表失败: ", error);
			displayError(error.message);
		}
	}

	/** 国家码→Emoji 旗帜 – 利用 Unicode 区域指示符 */
	function getFlagEmoji(countryCode) {
		if (!countryCode || countryCode.length !== 2) return '';
		const codePoints = countryCode.toUpperCase().split('').map(char => 127397 + char.charCodeAt());
		return String.fromCodePoint(...codePoints);
	}

	/** 秒数→人类可读 – 支持秒/分/时/天自动切换 */
	function formatDuration(seconds) {
		if (!seconds) return `0 ${translations[currentLang].outages_seconds}`;
		if (seconds < 60) return `${seconds} ${translations[currentLang].outages_seconds}`;
		if (seconds < 3600) return `${Math.round(seconds / 60)} ${translations[currentLang].outages_minutes}`;
		if (seconds < 86400) return `${(seconds / 3600).toFixed(1)} ${translations[currentLang].outages_hours}`;
		return `${(seconds / 86400).toFixed(1)} ${translations[currentLang].outages_days}`;
	}

	/** 字节→人类可读 – 自动选择 KB/MB/GB… */
	function formatBytes(bytes, decimals = 2) {
		if (!bytes || bytes === 0) return '0 Bytes';
		const k = 1024;
		const dm = decimals < 0 ? 0 : decimals;
		const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
	}



	/* =========================================================
	 * 视图渲染函数
	 * ========================================================= */
	/** 总调度 – 三大视图一次全刷新（地图、卡片、时间线） */
	function renderAllViews() {
		console.log('renderAllViews');
		if (document.getElementById('map-view').classList.contains('active')) initializeMap(monitorData.nodes);
		if (document.getElementById('standalones-view').classList.contains('active')) {
			generateStandalonesView(monitorData.nodes);
			generateTagFilters(monitorData.nodes);
		}
		if (document.getElementById('outages-view').classList.contains('active')) generateOutagesView(outagesData.outages);
	}

	// 更换页面
	async function switchView(viewId) {
		views.forEach(view => view.classList.remove('active'));
		document.getElementById(viewId)?.classList.add('active');
		switch (viewId) {
			case 'map-view':
			case 'standalones-view':
				fetchServerList();
				break;
			case 'outages-view':
				fetchOutageList();
				break;
		}
	}

	/** 1. 世界地图 – 动态插入 SVG 圆点 + Tooltip + 点击事件 */
	function initializeMap(nodes) {
		const svg = document.getElementById('world-map-svg');
		if (!svg) return;
		svg.querySelectorAll('.map-node').forEach(n => n.remove());
		const viewBoxAttr = svg.getAttribute('viewBox');
		if(viewBoxAttr) {
			const [width, height] = viewBoxAttr.split(' ').slice(2).map(parseFloat);
			if(width > 0 && height > 0) mapContainer.style.aspectRatio = `${width} / ${height}`;
		}
		nodes.forEach(node => {
			if (node.hasOwnProperty('x') && node.hasOwnProperty('y')) {
				const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
				circle.setAttribute('id', `map-node-${node.id}`);
				circle.setAttribute('class', `map-node ${!node.is_online ? 'anomaly' : ''}`);
				circle.setAttribute('cx', node.y); circle.setAttribute('cy', node.x); circle.setAttribute('r', 5);
				svg.appendChild(circle);
				circle.addEventListener('mouseenter', () => {
					let content = `<strong>${getFlagEmoji(node.country_code)} ${node.name}</strong>`;
					if (node.intro) content += `<br><span class="subtitle">${node.intro}</span>`;
					if (!node.is_online) content += `<br><span class="anomaly-subtitle">${node.anomaly_msg || translations[currentLang].tooltip_anomaly}</span>`;
					tooltip.innerHTML = content;
					tooltip.style.display = 'block';
				});
				circle.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
				circle.addEventListener('click', () => showDetailsModal(node.id));
			}
		});
	}

	/** 2. 独立服务器卡片 – 带标签筛选、分页、离线/高负载样式 */
	function generateStandalonesView(nodes) {
		const container = document.querySelector('#standalones-view .card-grid');
		container.innerHTML = '';
		
		const filteredNodes = nodes.filter(node => {
			const nodeTags = node.tags ? node.tags.split(',').map(t => t.trim()) : [];
			return activeTags.size === 0 || [...activeTags].every(tag => nodeTags.includes(tag));
		});

		const paginatedNodes = filteredNodes.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

		paginatedNodes.forEach(node => {
			const stats = node.stats || {};
			const isOffline = !node.is_online;
			const isHighLoad = !isOffline && stats.load_avg > 2.0;
			
			let cardClass = 'server-card';
			if (isOffline) cardClass += ' offline';
			if (isHighLoad) cardClass += ' high-load';

			const card = document.createElement('div');
			card.className = cardClass;
			card.dataset.serverId = node.id;

			const cpu = parseFloat(stats.cpu_usage || 0);
			const mem = parseFloat(stats.mem_usage_percent || 0);
			const disk = parseFloat(stats.disk_usage_percent || 0);
			const uptime = isOffline ? (formatDuration(node.outage_duration || 0)) : (stats.uptime || '...');

			card.innerHTML = `
				<div class="card-header">
					<div class="status-icon ${isOffline ? 'down' : ''}"></div>
					<div class="name">${getFlagEmoji(node.country_code)} ${node.name}</div>
				</div>
				<div class="stat-grid">
					<div class="label">${translations[currentLang].stat_cpu}</div>
					<div class="progress-bar"><div class="progress-bar-inner progress-cpu" style="width: ${cpu.toFixed(0)}%;">${cpu.toFixed(0)}%</div></div>
					<div class="label">${translations[currentLang].stat_mem}</div>
					<div class="progress-bar"><div class="progress-bar-inner progress-mem" style="width: ${mem.toFixed(0)}%;">${mem.toFixed(0)}%</div></div>
					<div class="label">${translations[currentLang].stat_disk}</div>
					<div class="progress-bar"><div class="progress-bar-inner progress-disk" style="width: ${disk.toFixed(0)}%;">${disk.toFixed(0)}%</div></div>
					<div class="label">${translations[currentLang].stat_net}</div>
					<div class="value">↑ ${formatBytes(stats.net_up_speed || 0)}/s | ↓ ${formatBytes(stats.net_down_speed || 0)}/s</div>
					<div class="label">${translations[currentLang].stat_traffic}</div>
					<div class="value">↑ ${formatBytes(stats.total_up || 0)} | ↓ ${formatBytes(stats.total_down || 0)}</div>
					<div class="label">${translations[currentLang].stat_load}</div>
					<div class="value">${parseFloat(stats.load_avg || 0).toFixed(2)}</div>
					<div class="label">${isOffline ? translations[currentLang].info_outage : translations[currentLang].stat_uptime}</div>
					<div class="value">${uptime}</div>
				</div>
			`;
			container.appendChild(card);
			card.addEventListener('click', () => showDetailsModal(node.id));
		});
		renderPaginationControls(filteredNodes.length);
	}

	/** 卡片底部分页按钮 – 根据过滤后总量生成页码 */
	function renderPaginationControls(totalItems) {
		const container = document.querySelector('.pagination');
		container.innerHTML = '';
		const totalPages = Math.ceil(totalItems / itemsPerPage);
		if (totalPages <= 1) return;

		for (let i = 1; i <= totalPages; i++) {
			const button = document.createElement('button');
			button.textContent = i;
			if (i === currentPage) button.classList.add('active');
			button.onclick = () => { currentPage = i; generateStandalonesView(monitorData.nodes); };
			container.appendChild(button);
		}
	}

	/**
	 * 3. 掉线时间线 – 按开始时间倒序，未恢复标红
	 * @param {Array} outages - 掉线事件数组，每个事件包含 id, server_id, start_time, end_time, title, content 等属性
	 * @description 生成掉线时间线视图：遍历事件，匹配节点，构建 HTML 时间线项，未恢复事件标红。
	 */
	function generateOutagesView(outages) {
		// 获取时间线容器元素
		const container = document.querySelector('#outages-view .timeline');
		// 清空容器内容
		container.innerHTML = '';
		
		// 如果没有掉线事件，显示无事件消息（使用当前语言翻译）
		if (!outages || outages.length === 0) {
			container.innerHTML = `<p>${translations[currentLang].outages_none}</p>`;
			return;
		}
		
		// 遍历每个掉线事件（假设 outages 已按 start_time 倒序排序）
		outages.forEach(outage => {
			// 生成节点名称：如果找到节点，使用国旗 Emoji + 名称；否则使用 server_id 作为 fallback
			const nodeName = `${getFlagEmoji(outage.country_code)} ${outage.name}`;
			
			// 格式化开始时间为本地化字符串（中文使用 'zh-CN' 格式，其他语言默认）
			const startTime = new Date(outage.start_time * 1000).toLocaleString(currentLang.startsWith('zh') ? 'zh-CN' : undefined);
			
			// 初始化内容为事件描述
			let content = outage.content;
			// 如果事件已恢复（有 end_time），追加恢复提示和持续时间
			if (outage.end_time) {
				content += `<br>${translations[currentLang].outages_recovered} ${formatDuration(outage.end_time - outage.start_time)}.`;
			}
			
			// 构建时间线项 HTML：添加 'critical' 类如果未恢复（用于 CSS 标红）
			const itemHTML = `<div class="timeline-item ${!outage.end_time ? 'critical' : ''}"><div class="time">${startTime}</div><div class="title">${outage.title} - ${nodeName}</div><div class="content">${content}</div></div>`;
			// 追加 HTML 到容器
			container.innerHTML += itemHTML;
		});
	}

	/** 标签过滤器 – 动态汇总所有节点标签，支持多选 */
	function generateTagFilters(nodes) {
		const container = document.querySelector('.tag-filters');
		const allTags = new Set();
		nodes.forEach(node => {
			if (node.tags) node.tags.split(',').forEach(tag => tag.trim() && allTags.add(tag.trim()));
		});

		container.innerHTML = `<strong>${translations[currentLang].filter_by} </strong>`;
		const allButton = document.createElement('button');
		allButton.textContent = translations[currentLang].filter_all;
		allButton.className = activeTags.size === 0 ? 'active' : '';
		allButton.onclick = () => { activeTags.clear(); currentPage = 1; renderAllViews(); };
		container.appendChild(allButton);

		allTags.forEach(tag => {
			const button = document.createElement('button');
			button.textContent = tag;
			button.className = activeTags.has(tag) ? 'active' : '';
			button.onclick = () => {
				activeTags.has(tag) ? activeTags.delete(tag) : activeTags.add(tag);
				currentPage = 1;
				renderAllViews();
			};
			container.appendChild(button);
		});
	}



	/* =========================================================
	 * 详情弹窗 – 含节点静态信息 + 6 张历史折线图
	 * ========================================================= */
	/** 打开弹窗 -> 拉取历史 -> 绘制 SVG 折线 */
	async function showDetailsModal(serverId) {
		const node = monitorData.nodes.find(n => n.id == serverId);
		if (!node) return;
		const modalBody = document.getElementById('modal-body');
		const isOffline = !node.is_online;
		
		modalBody.innerHTML = `
			<div class="modal-header"><h2>${getFlagEmoji(node.country_code)} ${node.name}</h2></div>
			<div class="modal-info-section">
					<div class="modal-info-item"><strong>${translations[currentLang].modal_intro}:</strong><p>${node.intro || 'N/A'}</p></div>
					<div class="info-grid">
					<div class="modal-info-item"><strong>${translations[currentLang].info_system}:</strong><p>${node.system || 'N/A'}</p></div>
					<div class="modal-info-item"><strong>${translations[currentLang].info_arch}:</strong><p>${node.arch || 'N/A'}</p></div>
					<div class="modal-info-item"><strong>${translations[currentLang].info_cpu}:</strong><p>${node.cpu_model || 'N/A'}</p></div>
					<div class="modal-info-item"><strong>${translations[currentLang].info_ram}:</strong><p>${formatBytes(node.mem_total, 2) || 'N/A'}</p></div>
					<div class="modal-info-item"><strong>${translations[currentLang].info_disk}:</strong><p>${formatBytes(node.disk_total, 2) || 'N/A'}</p></div>
					${isOffline ? `<div class="modal-info-item"><strong>${translations[currentLang].info_outage}:</strong><p>${formatDuration(node.outage_duration || 0)}</p></div>` : ''}
				</div>
			</div>
			<div class="chart-grid">
				<div class="chart-container"><h3>${translations[currentLang].modal_cpu_chart}</h3><div id="cpu-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
				<div class="chart-container"><h3>${translations[currentLang].modal_mem_chart}</h3><div id="mem-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
				<div class="chart-container"><h3>${translations[currentLang].modal_load_chart}</h3><div id="load-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
				<div class="chart-container"><h3>${translations[currentLang].modal_net_chart}</h3><div id="net-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
				<div class="chart-container"><h3>${translations[currentLang].modal_proc_chart}</h3><div id="proc-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
				<div class="chart-container"><h3>${translations[currentLang].modal_conn_chart}</h3><div id="conn-chart" class="chart-svg">${translations[currentLang].loading_charts}</div></div>
			</div>`;
		modalOverlay.classList.add('active');

		try {
			const response = await fetch(`./api.php?action=server&id=${serverId}`);
			const data = await response.json();
			if (data.error) throw new Error(data.error);

			const history = data.node.history || [];
			createSvgChart('cpu-chart', history.map(h => ({ x: h.timestamp, y: h.cpu_usage })), 100);
			createSvgChart('mem-chart', history.map(h => ({ x: h.timestamp, y: h.mem_usage_percent })), 100);
			createSvgChart('load-chart', history.map(h => ({ x: h.timestamp, y: h.load_avg })));
			createSvgChart('net-chart', [
				{ data: history.map(h => ({ x: h.timestamp, y: (h.net_up_speed || 0) / 1024 })), color: '#2ecc40' }, 
				{ data: history.map(h => ({ x: h.timestamp, y: (h.net_down_speed || 0) / 1024 })), color: '#0074d9' }
			]);
			createSvgChart('proc-chart', history.map(h => ({ x: h.timestamp, y: h.processes })));
			createSvgChart('conn-chart', history.map(h => ({ x: h.timestamp, y: h.connections })));
		} catch (err) {
			console.error('Failed to load history:', err);
			document.querySelector('.chart-grid').innerHTML = `<p style="color:red; text-align:center;">${translations[currentLang].error_loading}</p>`;
		}
	}

	/** 通用 SVG 折线生成器 – 支持单/多数据线、Grid、坐标轴 */
	function createSvgChart(elementId, datasets, forceMaxY) {
		const container = document.getElementById(elementId);
		if (!container) return;
		const NS = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS(NS, 'svg');
		svg.setAttribute('viewBox', '0 0 300 150');
		svg.setAttribute('preserveAspectRatio', 'none');
		container.innerHTML = '';
		container.appendChild(svg);
		
		const padding = { top: 10, right: 10, bottom: 20, left: 35 };

		// FIX: Correctly handle single vs multiple datasets
		if (Array.isArray(datasets) && datasets.length > 0 && typeof datasets[0].data === 'undefined') {
			datasets = [{ data: datasets, color: '#ffc107' }];
		}

		if (!datasets[0] || !datasets[0].data || datasets[0].data.length < 2) {
			container.innerHTML = `<div style="text-align:center; padding-top: 60px; color: #999;">${translations[currentLang].no_data_available}</div>`;
			return;
		}

		let allYValues = datasets.flatMap(d => d.data.map(p => p.y));
		const maxY = forceMaxY || Math.max(1, ...allYValues) * 1.2;
		const minX = datasets[0].data[0].x;
		const maxX = datasets[0].data[datasets[0].data.length - 1].x;

		for (let i = 0; i <= 4; i++) {
			const y = padding.top + i * (150 - padding.top - padding.bottom) / 4;
			const line = document.createElementNS(NS, 'line');
			line.setAttribute('x1', padding.left); line.setAttribute('y1', y);
			line.setAttribute('x2', 300 - padding.right); line.setAttribute('y2', y);
			line.setAttribute('class', 'grid-line');
			svg.appendChild(line);

			const text = document.createElementNS(NS, 'text');
			text.setAttribute('x', padding.left - 5); text.setAttribute('y', y + 3);
			text.setAttribute('text-anchor', 'end'); text.setAttribute('class', 'axis-text');
			text.textContent = (maxY * (1 - i / 4)).toFixed(forceMaxY === 100 ? 0 : 1);
			svg.appendChild(text);
		}

		datasets.forEach(dataset => {
			if(!dataset.data || dataset.data.length < 2) return;
			const path = document.createElementNS(NS, 'path');
			let d = 'M';
			dataset.data.forEach((point, i) => {
				const x = padding.left + (point.x - minX) / (maxX - minX || 1) * (300 - padding.left - padding.right);
				const y = (150 - padding.bottom) - (point.y / maxY) * (150 - padding.top - padding.bottom);
				d += `${x.toFixed(2)},${y.toFixed(2)} `;
			});
			path.setAttribute('d', d);
			path.setAttribute('class', 'line');
			path.style.stroke = dataset.color;
			svg.appendChild(path);
		});
	}



	/* =========================================================
	 * Signal 飞线动画 – 仅在「地图」视图且页签可见时触发
	 * ========================================================= */
	/** 随机选取两个在线节点 -> 计算角度 -> CSS 动画 */
	function fireSignal(nodes) {
		if (!nodes) return;
		const availableNodes = nodes.filter(n => n.is_online && n.x && n.y);
		if (availableNodes.length < 2) return;
		let startNode = availableNodes[Math.floor(Math.random() * availableNodes.length)];
		let endNode = availableNodes[Math.floor(Math.random() * availableNodes.length)];
		if(startNode.id === endNode.id) return;
		
		const signal = document.createElement('div');
		signal.className = 'signal';
		mapContainer.appendChild(signal);

		const containerRect = mapContainer.getBoundingClientRect();
		const svg = document.getElementById('world-map-svg');
		const viewBox = svg.viewBox.baseVal;
		const scaleX = containerRect.width / viewBox.width;
		const scaleY = containerRect.height / viewBox.height;
		
		const startX = (startNode.y - viewBox.x) * scaleX;
		const startY = (startNode.x - viewBox.y) * scaleY;
		const endX = (endNode.y - viewBox.x) * scaleX;
		const endY = (endNode.x - viewBox.y) * scaleY;

		const dx = endX - startX; const dy = endY - startY;
		const angle = Math.atan2(dy, dx) * 180 / Math.PI;
		
		signal.style.transform = `rotate(${angle}deg)`;
		signal.animate([
			{ left: `${startX}px`, top: `${startY}px`, width: '5px', opacity: 0.8 },
			{ width: `${Math.hypot(dx, dy) * 0.3}px`, opacity: 0.8, offset: 0.5 },
			{ left: `${endX}px`, top: `${endY}px`, width: '5px', opacity: 0 }
		], { duration: 1500, easing: 'ease-in-out' }).onfinish = () => signal.remove();
	}



	/* =========================================================
	 * 事件绑定
	 * ========================================================= */
	/** 顶部视图切换按钮 – 激活样式 + 显示对应区域 */
	controls.addEventListener('click', (e) => {
		if (e.target.tagName !== 'BUTTON') return;
		const viewName = e.target.dataset.view;
		if (activeButton) activeButton.classList.remove('active');
		e.target.classList.add('active');
		activeButton = e.target;
		switchView(viewName + '-view');
	});

	/** 语言切换 – 防止默认跳转 */
	langSelector.addEventListener('click', e => {
		if (e.target.tagName === 'A') {
			e.preventDefault();
			setLanguage(e.target.dataset.langCode);
		}
	});

	/** 弹窗关闭 – 点击 X 或蒙层均可 */
	modalCloseBtn.addEventListener('click', () => modalOverlay.classList.remove('active'));
	modalOverlay.addEventListener('click', (e) => {
		if (e.target === modalOverlay) modalOverlay.classList.remove('active');
	});

	/** Tooltip 跟随鼠标 – 地图容器内移动即刷新坐标 */
	mapContainer.addEventListener('mousemove', (e) => {
		const rect = mapContainer.getBoundingClientRect();
		tooltip.style.left = `${e.clientX - rect.left}px`;
		tooltip.style.top = `${e.clientY - rect.top}px`;
	});



	/* =========================================================
	 * 生命周期管理 – 自动刷新 / 页面可见性优化
	 * ========================================================= */
	let fetchDataInterval; // 数据轮询句柄
	let signalInterval; // Signal 动画定时句柄

	/** 启动轮询 & Signal – 先清后开，防止重复 */
	const startIntervals = () => {
		// 在开始新的轮询，先停止现有的轮询以防重复
		if (fetchDataInterval) clearInterval(fetchDataInterval);
		if (signalInterval) clearInterval(signalInterval);

		// 循环获取信息
		fetchServerList();
		fetchDataInterval = setInterval(() => {
			// 仅在地图页和详情页获取服务器列表
			if (document.getElementById('map-view').classList.contains('active') || document.getElementById('standalones-view').classList.contains('active')) {
				fetchServerList();
			} else if (document.getElementById('outages-view').classList.contains('active')) {
				fetchOutageList();
			}
		}, 10000);

		signalInterval = setInterval(() => {
			// 仅在地图页播放地图上的动画
			if (document.getElementById('map-view').classList.contains('active')) {
				//fireSignal(monitorData.nodes);
			}
		}, 800);
	};

	/** 停止所有定时器 – 页签隐藏时节省资源 */
	const stopIntervals = () => {
		clearInterval(fetchDataInterval);
		clearInterval(signalInterval);
	};

	/** 监听页签可见性 – 切走暂停，切回恢复 */
	document.addEventListener('visibilitychange', () => {
		if (document.hidden) {
			stopIntervals(); // Stop fetching when tab is not visible
		} else {
			startIntervals(); // Start fetching when tab becomes visible
		}
	});



	/* -------------------- 首次初始化 -------------------- */
	const savedLang = localStorage.getItem('pintyLang') || 'zh';
	setLanguage(savedLang);
	startIntervals(); // 拉数据 + 启动动画
	fetchWebInfoData();
});
