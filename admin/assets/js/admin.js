/**
 * WP LLM SEO & Indexing - Admin JavaScript
 * 
 * Client-side routing and Chart.js integration.
 */

(function() {
	'use strict';

	/**
	 * Admin Router
	 * Handles client-side navigation and dynamic content loading.
	 */
	const WPLLMSEORouter = {
		/**
		 * Initialize the router
		 */
		init: function() {
			this.bindEvents();
			this.initCharts();
			this.loadDashboardCharts();
			this.highlightActiveLink();
		},

		/**
		 * Bind event listeners
		 */
		bindEvents: function() {
			// Handle sidebar navigation clicks
			const navLinks = document.querySelectorAll('.wpllmseo-nav-menu a');
			navLinks.forEach(link => {
				link.addEventListener('click', (e) => {
					this.handleNavClick(e, link);
				});
			});

			// Handle table sorting
			const sortableHeaders = document.querySelectorAll('.wpllmseo-table th.sortable');
			sortableHeaders.forEach(header => {
				header.addEventListener('click', (e) => {
					this.handleTableSort(e, header);
				});
			});

			// Handle log level filter
			const logFilter = document.getElementById('log-level-filter');
			if (logFilter) {
				logFilter.addEventListener('change', (e) => {
					this.filterLogTable(e.target.value);
				});
			}

			// Handle Run Worker button
			const runWorkerBtn = document.querySelector('.wpllmseo-run-worker');
			if (runWorkerBtn) {
				runWorkerBtn.addEventListener('click', (e) => {
					e.preventDefault();
					this.runWorker();
				});
			}

			// Handle Clear Completed button
			const clearCompletedBtn = document.querySelector('.wpllmseo-clear-completed');
			if (clearCompletedBtn) {
				clearCompletedBtn.addEventListener('click', (e) => {
					e.preventDefault();
					this.clearCompletedJobs();
				});
			}

			// Handle log section accordion
			const logHeaders = document.querySelectorAll('.wpllmseo-log-section-header');
			logHeaders.forEach(header => {
				header.addEventListener('click', (e) => {
					this.toggleLogSection(header);
				});
			});
		},

		/**
		 * Handle navigation link clicks
		 */
		handleNavClick: function(e, link) {
			// Update active state
			const allLinks = document.querySelectorAll('.wpllmseo-nav-menu li');
			allLinks.forEach(li => li.classList.remove('active'));
			
			const parentLi = link.closest('li');
			if (parentLi) {
				parentLi.classList.add('active');
			}

			// Update aria-current
			document.querySelectorAll('.wpllmseo-nav-menu a').forEach(a => {
				a.setAttribute('aria-current', 'false');
			});
			link.setAttribute('aria-current', 'page');
		},

		/**
		 * Highlight active navigation link based on current page
		 */
		highlightActiveLink: function() {
			const currentPage = new URLSearchParams(window.location.search).get('page');
			if (!currentPage) return;

			const links = document.querySelectorAll('.wpllmseo-nav-menu a');
			links.forEach(link => {
				const linkUrl = new URL(link.href);
				const linkPage = new URLSearchParams(linkUrl.search).get('page');
				
				if (linkPage === currentPage) {
					const parentLi = link.closest('li');
					if (parentLi) {
						parentLi.classList.add('active');
					}
					link.setAttribute('aria-current', 'page');
				}
			});
		},

		/**
		 * Handle table sorting
		 */
		handleTableSort: function(e, header) {
			const table = header.closest('table');
			const tbody = table.querySelector('tbody');
			const rows = Array.from(tbody.querySelectorAll('tr'));
			const columnKey = header.dataset.column;
			const columnIndex = Array.from(header.parentElement.children).indexOf(header);

			// Determine sort direction
			const currentSort = header.dataset.sort || 'none';
			const newSort = currentSort === 'asc' ? 'desc' : 'asc';

			// Reset all headers
			table.querySelectorAll('th.sortable').forEach(th => {
				th.dataset.sort = 'none';
				th.classList.remove('sorted-asc', 'sorted-desc');
			});

			// Set new sort direction
			header.dataset.sort = newSort;
			header.classList.add(`sorted-${newSort}`);

			// Sort rows
			rows.sort((a, b) => {
				const aCell = a.cells[columnIndex];
				const bCell = b.cells[columnIndex];
				
				if (!aCell || !bCell) return 0;

				let aValue = aCell.textContent.trim();
				let bValue = bCell.textContent.trim();

				// Try to parse as numbers
				const aNum = parseFloat(aValue);
				const bNum = parseFloat(bValue);

				if (!isNaN(aNum) && !isNaN(bNum)) {
					return newSort === 'asc' ? aNum - bNum : bNum - aNum;
				}

				// String comparison
				return newSort === 'asc' 
					? aValue.localeCompare(bValue)
					: bValue.localeCompare(aValue);
			});

			// Re-append sorted rows
			rows.forEach(row => tbody.appendChild(row));
		},

		/**
		 * Filter log table by level
		 */
		filterLogTable: function(level) {
			const table = document.getElementById('logs-table');
			if (!table) return;

			const rows = table.querySelectorAll('tbody tr');
			rows.forEach(row => {
				if (level === 'all') {
					row.style.display = '';
					return;
				}

				const logLevelCell = row.querySelector('.wpllmseo-log-level');
				if (!logLevelCell) return;

				const rowLevel = logLevelCell.textContent.trim().toLowerCase();
				row.style.display = rowLevel === level ? '' : 'none';
			});
		},

		/**
		 * Initialize all charts on the page
		 */
		initCharts: function() {
			// Check if Chart.js is loaded
			if (typeof Chart === 'undefined') {
				console.warn('Chart.js is not loaded. Charts will not be displayed.');
				return;
			}

			// Initialize main dashboard chart
			const mainChart = document.getElementById('main-dashboard-chart');
			if (mainChart) {
				this.initMainChart(mainChart);
			}

			// Initialize sparkline charts
			this.initSparklines();
		},

		/**
		 * Initialize the main dashboard chart
		 */
		initMainChart: function(canvas) {
			const chartData = canvas.dataset.chart;
			if (!chartData) return;

			let data;
			try {
				data = JSON.parse(chartData);
			} catch (e) {
				console.error('Failed to parse chart data:', e);
				return;
			}

			const ctx = canvas.getContext('2d');
			new Chart(ctx, {
				type: 'line',
				data: data,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'top',
							labels: {
								usePointStyle: true,
								padding: 20,
								color: getComputedStyle(document.documentElement)
									.getPropertyValue('--wpllmseo-text').trim()
							}
						},
						tooltip: {
							mode: 'index',
							intersect: false,
							backgroundColor: 'rgba(0, 0, 0, 0.8)',
							padding: 12,
							cornerRadius: 4
						}
					},
					scales: {
						x: {
							grid: {
								display: false
							},
							ticks: {
								color: getComputedStyle(document.documentElement)
									.getPropertyValue('--wpllmseo-text-muted').trim()
							}
						},
						y: {
							beginAtZero: true,
							grid: {
								color: getComputedStyle(document.documentElement)
									.getPropertyValue('--wpllmseo-border').trim()
							},
							ticks: {
								color: getComputedStyle(document.documentElement)
									.getPropertyValue('--wpllmseo-text-muted').trim()
							}
						}
					},
					interaction: {
						mode: 'nearest',
						axis: 'x',
						intersect: false
					}
				}
			});
		},

		/**
		 * Initialize sparkline charts in stat cards
		 */
		initSparklines: function() {
			const sparklines = [
				{ id: 'sparkline-indexed', data: [35, 42, 38, 45, 52, 48, 55] },
				{ id: 'sparkline-queue', data: [12, 15, 18, 22, 20, 23, 23] },
				{ id: 'sparkline-snippets', data: [8, 12, 10, 15, 18, 16, 21] }
			];

			sparklines.forEach(sparkline => {
				const canvas = document.getElementById(sparkline.id);
				if (!canvas) return;

				const ctx = canvas.getContext('2d');
				new Chart(ctx, {
					type: 'line',
					data: {
						labels: ['', '', '', '', '', '', ''],
						datasets: [{
							data: sparkline.data,
							borderColor: getComputedStyle(document.documentElement)
								.getPropertyValue('--wpllmseo-primary').trim(),
							borderWidth: 2,
							fill: true,
							backgroundColor: 'rgba(34, 113, 177, 0.1)',
							tension: 0.4,
							pointRadius: 0,
							pointHoverRadius: 4
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							legend: { display: false },
							tooltip: { enabled: false }
						},
						scales: {
							x: { display: false },
							y: { display: false }
						}
					}
				});
			});
		},

		/**
		 * Load dashboard charts via AJAX
		 */
		loadDashboardCharts: function() {
			const chunksChart = document.getElementById('daily-chunks-chart');
			const ragChart = document.getElementById('daily-rag-queries-chart');

			if (!chunksChart && !ragChart) return;

			// Fetch chart data
			fetch(wpllmseo_admin.rest_url + 'wp-llmseo/v1/dashboard/charts', {
				method: 'GET',
				headers: {
					'X-WP-Nonce': wpllmseo_admin.nonce
				}
			})
			.then(response => response.json())
			.then(result => {
				if (!result.success) {
					console.error('Failed to load chart data');
					return;
				}

				const data = result.data;

				// Render Daily Chunks Chart
				if (chunksChart && data.daily_chunks) {
					this.renderDailyChart(chunksChart, data.daily_chunks, 'Chunks Indexed', '#2271b1');
					document.querySelector('[data-chart="chunks"]').style.display = 'none';
				}

				// Render Daily RAG Queries Chart
				if (ragChart && data.daily_rag_queries) {
					this.renderDailyChart(ragChart, data.daily_rag_queries, 'RAG Queries', '#00a32a');
					document.querySelector('[data-chart="rag"]').style.display = 'none';
				}
			})
			.catch(error => {
				console.error('Error loading chart data:', error);
			});
		},

		/**
		 * Render daily chart
		 */
		renderDailyChart: function(canvas, data, label, color) {
			const ctx = canvas.getContext('2d');
			
			const labels = data.map(item => {
				const date = new Date(item.date);
				return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
			});

			const counts = data.map(item => item.count);

			new Chart(ctx, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: label,
						data: counts,
						borderColor: color,
						backgroundColor: color + '20',
						tension: 0.4,
						fill: true,
						borderWidth: 2,
						pointRadius: 3,
						pointHoverRadius: 5
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'top'
						},
						tooltip: {
							mode: 'index',
							intersect: false
						}
					},
					scales: {
						x: {
							grid: { display: false }
						},
						y: {
							beginAtZero: true,
							ticks: {
								precision: 0
							}
						}
					}
				}
			});
		},

		/**
		 * Run worker manually
		 */
		runWorker: function() {
			const btn = document.querySelector('.wpllmseo-run-worker');
			if (!btn) return;

			const originalText = btn.textContent;
			const icon = btn.querySelector('.dashicons');
			
			btn.disabled = true;
			btn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> Running...';

			fetch(wpllmseo_admin.rest_url + 'wp-llmseo/v1/run-worker', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': wpllmseo_admin.nonce,
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					limit: 5, // Optimized for token usage
					bypass_cooldown: false
				})
			})
			.then(response => response.json())
			.then(result => {
				btn.disabled = false;
				if (icon) {
					btn.innerHTML = icon.outerHTML + ' ' + originalText;
				} else {
					btn.textContent = originalText;
				}

				if (result.success) {
					const processed = result.data?.processed || 0;
					this.showNotice('Worker executed successfully! Processed ' + processed + ' items. Next run available in 24 hours.', 'success');
					setTimeout(() => location.reload(), 1500);
				} else {
					// Check if cooldown is active
					if (result.data?.cooldown_active) {
						this.showNotice('Cooldown active: ' + result.message, 'warning');
					} else {
						this.showNotice('Worker execution failed: ' + (result.message || 'Unknown error'), 'error');
					}
				}
			})
			.catch(error => {
				btn.disabled = false;
				if (icon) {
					btn.innerHTML = icon.outerHTML + ' ' + originalText;
				} else {
					btn.textContent = originalText;
				}
				this.showNotice('Error running worker: ' + error.message, 'error');
			});
		},

		/**
		 * Clear completed jobs from queue
		 */
		clearCompletedJobs: function() {
			if (!confirm('Are you sure you want to clear all completed jobs from the queue?')) {
				return;
			}

			const btn = document.querySelector('.wpllmseo-clear-completed');
			if (!btn) return;

			const originalText = btn.textContent;
			const icon = btn.querySelector('.dashicons');
			
			btn.disabled = true;
			btn.innerHTML = '<span class="dashicons dashicons-update dashicons-spin"></span> Clearing...';

			// Use WordPress AJAX instead of REST API for simpler queue management
			const formData = new FormData();
			formData.append('action', 'wpllmseo_clear_completed');
			formData.append('nonce', wpllmseo_admin.nonce);

			fetch(wpllmseo_admin.ajax_url, {
				method: 'POST',
				body: formData
			})
			.then(response => response.json())
			.then(result => {
				btn.disabled = false;
				if (icon) {
					btn.innerHTML = icon.outerHTML + ' ' + originalText;
				} else {
					btn.textContent = originalText;
				}

				if (result.success) {
					this.showNotice('Cleared ' + (result.data?.deleted || 0) + ' completed jobs.', 'success');
					setTimeout(() => location.reload(), 1000);
				} else {
					this.showNotice('Failed to clear completed jobs: ' + (result.message || 'Unknown error'), 'error');
				}
			})
			.catch(error => {
				btn.disabled = false;
				if (icon) {
					btn.innerHTML = icon.outerHTML + ' ' + originalText;
				} else {
					btn.textContent = originalText;
				}
				this.showNotice('Error clearing completed jobs: ' + error.message, 'error');
			});
		},

		/**
		 * Show notice message
		 */
		showNotice: function(message, type = 'info') {
			const wrap = document.querySelector('.wrap');
			if (!wrap) return;

			const notice = document.createElement('div');
			notice.className = `notice notice-${type} is-dismissible`;
			notice.innerHTML = `<p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>`;
			
			// Insert after header
			const header = wrap.querySelector('h1');
			if (header) {
				header.parentNode.insertBefore(notice, header.nextSibling);
			} else {
				wrap.insertBefore(notice, wrap.firstChild);
			}

			// Add dismiss handler
			const dismissBtn = notice.querySelector('.notice-dismiss');
			if (dismissBtn) {
				dismissBtn.addEventListener('click', () => {
					notice.style.opacity = '0';
					setTimeout(() => notice.remove(), 300);
				});
			}

			// Auto dismiss success messages
			if (type === 'success') {
				setTimeout(() => {
					if (notice.parentNode) {
						notice.style.opacity = '0';
						setTimeout(() => notice.remove(), 300);
					}
				}, 5000);
			}
		},

		/**
		 * Toggle log section accordion
		 */
		toggleLogSection: function(header) {
			const isExpanded = header.getAttribute('aria-expanded') === 'true';
			const contentId = header.getAttribute('aria-controls');
			const content = document.getElementById(contentId);

			if (!content) return;

			if (isExpanded) {
				header.setAttribute('aria-expanded', 'false');
				content.style.display = 'none';
			} else {
				header.setAttribute('aria-expanded', 'true');
				content.style.display = 'block';
			}
		}
	};

	/**
	 * Initialize when DOM is ready
	 */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			WPLLMSEORouter.init();
		});
	} else {
		WPLLMSEORouter.init();
	}

	// Expose to global scope for debugging
	window.WPLLMSEORouter = WPLLMSEORouter;

})();
