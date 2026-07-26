(function () {
	'use strict';

	if (!window.gtlmAdmin) {
		return;
	}

	var table = document.querySelector('.wp-list-table');

	function removeQuickEditor() {
		var existing = document.querySelector('.gtlm-quick-edit-row');
		if (existing && existing.parentNode) {
			existing.parentNode.removeChild(existing);
		}
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				if (key === 'className') {
					node.className = attrs[key];
				} else if (key === 'textContent') {
					node.textContent = attrs[key];
				} else {
					node.setAttribute(key, attrs[key]);
				}
			});
		}
		if (children) {
			children.forEach(function (child) {
				if (typeof child === 'string') {
					node.appendChild(document.createTextNode(child));
				} else if (child) {
					node.appendChild(child);
				}
			});
		}
		return node;
	}

	function buildQuickEditRow(tr, data) {
		removeQuickEditor();

		var colCount = tr.children.length;
		var quickTr = el('tr', { className: 'gtlm-quick-edit-row inline-edit-row' });
		var td = el('td', { colspan: colCount });

		var wrap = el('div', { className: 'gtlm-quick-edit-wrap' });

		// Row 1: URL + Type
		var row1 = el('div', { className: 'gtlm-qe-row' });
		var urlLabel = el('label', { textContent: 'Destination URL ' });
		var urlInput = el('input', { type: 'url', className: 'gtlm-quick-url', value: data.url });
		urlLabel.appendChild(urlInput);

		var typeLabel = el('label', { textContent: 'Type ' });
		var typeSelect = el('select', { className: 'gtlm-quick-type' });
		['301', '302', '307'].forEach(function (val) {
			typeSelect.appendChild(el('option', { value: val, textContent: val }));
		});
		typeSelect.value = String(data.redirectType);
		typeLabel.appendChild(typeSelect);

		row1.appendChild(urlLabel);
		row1.appendChild(document.createTextNode(' '));
		row1.appendChild(typeLabel);

		// Row 2: Slug + Rel + Category + Status
		var row2 = el('div', { className: 'gtlm-qe-row' });

		var slugLabel = el('label', { textContent: 'Slug ' });
		var slugInput = el('input', { type: 'text', className: 'gtlm-quick-slug', value: data.slug || '' });
		slugLabel.appendChild(slugInput);

		var relFieldset = el('span', { className: 'gtlm-qe-rel' });
		relFieldset.appendChild(document.createTextNode('Rel '));
		var relValues = (data.rel || '').split(',').filter(Boolean);
		['nofollow', 'sponsored', 'ugc'].forEach(function (val) {
			var lbl = el('label');
			var cb = el('input', { type: 'checkbox', name: 'rel', value: val });
			if (relValues.indexOf(val) !== -1) {
				cb.checked = true;
			}
			lbl.appendChild(cb);
			lbl.appendChild(document.createTextNode(' ' + val + ' '));
			relFieldset.appendChild(lbl);
		});

		var catLabel = el('label', { textContent: 'Category ' });
		var catSelect = el('select', { className: 'gtlm-quick-category' });
		catSelect.appendChild(el('option', { value: '0', textContent: 'None' }));
		(window.gtlmAdmin.categories || []).forEach(function (cat) {
			catSelect.appendChild(el('option', { value: String(cat.id), textContent: cat.name }));
		});
		catSelect.value = String(data.categoryId || 0);
		catLabel.appendChild(catSelect);

		var statusLabel = el('label', { textContent: 'Status ' });
		var statusSelect = el('select', { className: 'gtlm-quick-status' });
		statusSelect.appendChild(el('option', { value: '1', textContent: 'Active' }));
		statusSelect.appendChild(el('option', { value: '0', textContent: 'Inactive' }));
		statusSelect.value = String(data.isActive);
		statusLabel.appendChild(statusSelect);

		row2.appendChild(slugLabel);
		row2.appendChild(document.createTextNode(' '));
		row2.appendChild(relFieldset);
		row2.appendChild(document.createTextNode(' '));
		row2.appendChild(catLabel);
		row2.appendChild(document.createTextNode(' '));
		row2.appendChild(statusLabel);

		// Row 3: Buttons
		var row3 = el('div', { className: 'gtlm-qe-row' });
		var saveBtn = el('button', { type: 'button', className: 'button button-primary gtlm-quick-save', textContent: 'Save' });
		var cancelBtn = el('button', { type: 'button', className: 'button gtlm-quick-cancel', textContent: 'Cancel' });
		var spinner = el('span', { className: 'spinner', style: 'float:none;margin:0 0 0 8px;' });
		var message = el('span', { className: 'gtlm-quick-message', style: 'margin-left:10px;' });
		row3.appendChild(saveBtn);
		row3.appendChild(document.createTextNode(' '));
		row3.appendChild(cancelBtn);
		row3.appendChild(spinner);
		row3.appendChild(message);

		wrap.appendChild(row1);
		wrap.appendChild(row2);
		wrap.appendChild(row3);
		td.appendChild(wrap);
		quickTr.appendChild(td);

		tr.parentNode.insertBefore(quickTr, tr.nextSibling);

		cancelBtn.addEventListener('click', removeQuickEditor);

		saveBtn.addEventListener('click', function () {
			var msgEl = quickTr.querySelector('.gtlm-quick-message');
			var spinEl = quickTr.querySelector('.spinner');
			var formData = new window.FormData();

			msgEl.textContent = '';
			spinEl.classList.add('is-active');

			formData.append('action', 'gtlm_quick_edit');
			formData.append('nonce', window.gtlmAdmin.quickEditNonce);
			formData.append('link_id', data.linkId);
			formData.append('url', quickTr.querySelector('.gtlm-quick-url').value);
			formData.append('redirect_type', quickTr.querySelector('.gtlm-quick-type').value);
			formData.append('slug', quickTr.querySelector('.gtlm-quick-slug').value);
			formData.append('category_id', quickTr.querySelector('.gtlm-quick-category').value);
			formData.append('is_active', quickTr.querySelector('.gtlm-quick-status').value);

			var relChecked = quickTr.querySelectorAll('input[name="rel"]:checked');
			if (relChecked.length > 0) {
				relChecked.forEach(function (cb) {
					formData.append('rel[]', cb.value);
				});
			} else {
				formData.append('rel', '');
			}

			window.fetch(window.gtlmAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
				.then(function (res) { return res.json(); })
				.then(function (json) {
					spinEl.classList.remove('is-active');
					if (!json || !json.success) {
						msgEl.textContent = window.gtlmAdmin.i18n.saveFailed;
						msgEl.style.color = '#b32d2e';
						return;
					}

					var d = json.data || {};

					// Update visible cells
					var destCell = tr.querySelector('td.column-url a');
					if (destCell && d.url) {
						destCell.href = d.url;
						destCell.textContent = d.url;
					}
					var typeCell = tr.querySelector('td.column-redirect_type');
					if (typeCell && d.redirect_type) {
						typeCell.textContent = d.redirect_type;
					}
					var relCell = tr.querySelector('td.column-rel');
					if (relCell) {
						relCell.textContent = d.rel || '';
					}
					var statusCell = tr.querySelector('td.column-status');
					if (statusCell) {
						var isActive = parseInt(d.is_active, 10);
						statusCell.innerHTML = isActive
							? '<span class="gtlm-status gtlm-status--active">Active</span>'
							: '<span class="gtlm-status gtlm-status--inactive">Inactive</span>';
					}
					var catCell = tr.querySelector('td.column-category');
					if (catCell) {
						var catName = '\u2014';
						(window.gtlmAdmin.categories || []).forEach(function (c) {
							if (c.id === d.category_id) {
								catName = c.name;
							}
						});
						catCell.textContent = catName;
					}
					var brandedCell = tr.querySelector('td.column-branded_url code');
					if (brandedCell && d.slug) {
						brandedCell.textContent = window.location.origin + '/' + (window.gtlmAdmin.prefix || 'go') + '/' + d.slug;
					}

					// Update quick edit data attributes
					var qeLink = tr.querySelector('.gtlm-quick-edit');
					if (qeLink) {
						qeLink.setAttribute('data-url', d.url || '');
						qeLink.setAttribute('data-redirect-type', d.redirect_type || '301');
						qeLink.setAttribute('data-slug', d.slug || '');
						qeLink.setAttribute('data-rel', d.rel || '');
						qeLink.setAttribute('data-category-id', d.category_id || 0);
						qeLink.setAttribute('data-is-active', d.is_active);
					}

					msgEl.textContent = window.gtlmAdmin.i18n.saved;
					msgEl.style.color = '#008a20';
					window.setTimeout(removeQuickEditor, 600);
				})
				.catch(function () {
					spinEl.classList.remove('is-active');
					msgEl.textContent = window.gtlmAdmin.i18n.saveFailed;
					msgEl.style.color = '#b32d2e';
				});
		});
	}

	document.addEventListener('click', function (event) {
		var quickLink = event.target.closest('.gtlm-quick-edit');
		if (quickLink) {
			event.preventDefault();
			var tr = quickLink.closest('tr');
			if (!tr) {
				return;
			}
			buildQuickEditRow(tr, {
				linkId: quickLink.getAttribute('data-link-id'),
				url: quickLink.getAttribute('data-url') || '',
				redirectType: quickLink.getAttribute('data-redirect-type') || '301',
				slug: quickLink.getAttribute('data-slug') || '',
				rel: quickLink.getAttribute('data-rel') || '',
				categoryId: quickLink.getAttribute('data-category-id') || '0',
				isActive: quickLink.getAttribute('data-is-active') || '1'
			});
			return;
		}

		var copyLink = event.target.closest('.gtlm-copy-url');
		if (copyLink) {
			event.preventDefault();
			var copyUrl = copyLink.getAttribute('data-copy-url') || '';
			if (!copyUrl) {
				return;
			}
			window.navigator.clipboard.writeText(copyUrl).then(function () {
				copyLink.textContent = window.gtlmAdmin.i18n.copied;
				window.setTimeout(function () {
					copyLink.textContent = window.gtlmAdmin.i18n.copyUrl;
				}, 1200);
			});
		}
	});

	var nameField = document.getElementById('name');
	var slugField = document.getElementById('slug');
	var prefix = window.gtlmAdmin.prefix || 'go';
	var preview = document.getElementById('gtlm-branded-preview');
	var copyBtn = document.getElementById('gtlm-copy-preview');
	var slugTouched = false;

	function getSelectedMode() {
		var checked = document.querySelector('input[name="link_mode"]:checked');
		return checked ? checked.value : 'standard';
	}

	function slugify(str) {
		return String(str || '')
			.toLowerCase()
			.trim()
			.replace(/[^a-z0-9\s-]/g, '')
			.replace(/\s+/g, '-')
			.replace(/-+/g, '-');
	}

	function updatePreview() {
		if (!preview || !slugField) {
			return;
		}
		var slug = slugField.value.trim();
		if (!slug) {
			preview.textContent = '-';
			return;
		}
		var mode = getSelectedMode();
		if (mode === 'direct') {
			preview.textContent = window.location.origin + '/' + slug;
		} else if (mode === 'regex') {
			preview.textContent = slug + ' (regex pattern)';
		} else {
			preview.textContent = window.location.origin + '/' + prefix + '/' + slug;
		}
	}

	function updateModeUI(mode) {
		// Show/hide regex-only fields.
		var regexFields = document.querySelectorAll('.gtlm-field-regex-replacement, .gtlm-field-priority');
		regexFields.forEach(function (el) {
			el.style.display = mode === 'regex' ? '' : 'none';
		});

		// Show/hide mode hints.
		var hints = document.querySelectorAll('.gtlm-mode-hint');
		hints.forEach(function (el) {
			el.style.display = el.getAttribute('data-mode') === mode ? '' : 'none';
		});

		// Update slug field label.
		var slugLabel = slugField ? slugField.closest('tr') : null;
		if (slugLabel) {
			var label = slugLabel.querySelector('label');
			if (label) {
				if (mode === 'direct') {
					label.textContent = 'Path';
				} else if (mode === 'regex') {
					label.textContent = 'Pattern';
				} else {
					label.textContent = 'Slug';
				}
			}
		}

		updatePreview();
	}

	// Bind link mode radio buttons.
	var modeRadios = document.querySelectorAll('.gtlm-link-mode-radio');
	modeRadios.forEach(function (radio) {
		radio.addEventListener('change', function () {
			updateModeUI(this.value);
		});
	});

	if (modeRadios.length > 0) {
		updateModeUI(getSelectedMode());
	}

	if (nameField && slugField) {
		nameField.addEventListener('input', function () {
			var mode = getSelectedMode();
			if (mode === 'standard' && (!slugTouched || !slugField.value.trim())) {
				slugField.value = slugify(nameField.value);
			}
			updatePreview();
		});
		slugField.addEventListener('input', function () {
			slugTouched = true;
			var mode = getSelectedMode();
			if (mode === 'standard') {
				slugField.value = slugify(slugField.value);
			}
			updatePreview();
		});
		updatePreview();
	}

	if (copyBtn && preview) {
		copyBtn.addEventListener('click', function () {
			var text = preview.textContent || '';
			if (!text || text === '-') {
				return;
			}
			window.navigator.clipboard.writeText(text).then(function () {
				copyBtn.textContent = window.gtlmAdmin.i18n.copied;
				window.setTimeout(function () {
					copyBtn.textContent = window.gtlmAdmin.i18n.copyUrl;
				}, 1200);
			});
		});
	}

	var importForm = document.getElementById('gtlm-import-form');
	var progressWrap = document.getElementById('gtlm-import-progress-wrap');
	var progressBar = document.getElementById('gtlm-import-progress');
	if (importForm && progressWrap && progressBar) {
		importForm.addEventListener('submit', function () {
			progressWrap.style.display = 'block';
			progressBar.removeAttribute('value');
		});
	}

	// Row highlight after save.
	var highlightId = parseInt(window.gtlmAdmin.highlight, 10);
	if (highlightId > 0 && table) {
		var checkbox = table.querySelector('input[name="link_ids[]"][value="' + highlightId + '"]');
		if (checkbox) {
			var row = checkbox.closest('tr');
			if (row) {
				row.classList.add('gtlm-highlight');
				window.setTimeout(function () {
					row.classList.remove('gtlm-highlight');
				}, 2400);
			}
		}
	}

	// Geolocation rule builder on the link editor.
	(function () {
		var toggle = document.getElementById('gtlm-geo-toggle');
		var rows = document.getElementById('gtlm-geo-rows');
		var addBtn = document.getElementById('gtlm-geo-add-rule');
		var template = document.getElementById('gtlm-geo-row-template');
		var warnings = document.getElementById('gtlm-geo-warnings');
		var testSelect = document.getElementById('gtlm-geo-test-country');
		var testResult = document.getElementById('gtlm-geo-test-result');
		var fallback = document.getElementById('gtlm-geo-fallback');

		if (!rows) {
			return;
		}

		var i18n = (window.gtlmAdmin && window.gtlmAdmin.i18n) || {};

		function ruleRows() {
			return Array.prototype.slice.call(rows.querySelectorAll('tr.gtlm-geo-rule'));
		}

		function selectedIn(row) {
			var select = row.querySelector('.gtlm-geo-countries');
			if (!select) {
				return [];
			}
			return Array.prototype.filter
				.call(select.options, function (o) {
					return o.selected;
				})
				.map(function (o) {
					return { code: o.value, label: o.text };
				});
		}

		// Field names carry a row index so each row's country multi-select
		// posts as its own array. Indices must stay contiguous after edits.
		function renumber() {
			ruleRows().forEach(function (row, i) {
				row.setAttribute('data-index', i);
				Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
					field.name = field.name.replace(/\[(?:\d+|__INDEX__)\]/, '[' + i + ']');
				});
				var badge = row.querySelector('.gtlm-geo-order-badge');
				if (badge) {
					badge.textContent = String(i + 1);
				}
			});
		}

		// Show the current selection as readable chips; a 249-option list is
		// impossible to read at a glance otherwise.
		function renderChips(row) {
			var host = row.querySelector('.gtlm-geo-chips');
			if (!host) {
				return;
			}
			var picked = selectedIn(row);
			host.innerHTML = '';
			if (!picked.length) {
				var empty = document.createElement('span');
				empty.className = 'description';
				empty.textContent = i18n.geoNoCountries || 'No countries selected yet — this rule will be ignored.';
				host.appendChild(empty);
				return;
			}
			picked.forEach(function (item) {
				var name = item.label.replace(/\s*\([A-Z]{2}\)$/, '');
				var chip = document.createElement('button');
				chip.type = 'button';
				chip.className = 'gtlm-geo-chip';
				chip.setAttribute('data-code', item.code);
				// The chip's text is just the country, so the action has to be
				// in the accessible name or it reads as a meaningless button.
				chip.setAttribute('aria-label', (i18n.geoRemoveNamed || 'Remove %s').replace('%s', name));
				chip.title = i18n.geoRemoveCountry || 'Remove';
				chip.textContent = name;
				host.appendChild(chip);
			});
		}

		// A country claimed by an earlier rule can never reach a later one.
		function renderWarnings() {
			if (!warnings) {
				return;
			}
			var seen = {};
			var shadowed = [];
			var noUrl = 0;

			ruleRows().forEach(function (row) {
				var url = row.querySelector('.gtlm-geo-url');
				var picked = selectedIn(row);
				if (picked.length && (!url || !url.value.trim())) {
					noUrl++;
				}
				picked.forEach(function (item) {
					if (seen[item.code]) {
						shadowed.push(item.label.replace(/\s*\([A-Z]{2}\)$/, ''));
					} else {
						seen[item.code] = true;
					}
				});
			});

			var messages = [];
			if (shadowed.length) {
				messages.push(
					(i18n.geoShadowed || 'Listed more than once, so only the highest rule applies:') +
						' ' +
						shadowed.join(', ')
				);
			}
			if (noUrl) {
				messages.push(i18n.geoMissingUrl || 'A rule has countries but no destination URL, so it will be discarded on save.');
			}

			warnings.innerHTML = '';
			if (!messages.length) {
				return;
			}
			var notice = document.createElement('div');
			notice.className = 'notice notice-warning inline';
			messages.forEach(function (text) {
				var p = document.createElement('p');
				p.textContent = text;
				notice.appendChild(p);
			});
			warnings.appendChild(notice);
		}

		// Resolve the in-progress form the same way the server would.
		function runPreview() {
			if (!testSelect || !testResult) {
				return;
			}
			var code = testSelect.value;
			if (!code) {
				testResult.textContent = '';
				testResult.className = 'gtlm-geo-test-result';
				return;
			}

			var winner = null;
			ruleRows().some(function (row) {
				var codes = selectedIn(row).map(function (i) {
					return i.code;
				});
				var hit =
					codes.indexOf(code) !== -1 ||
					(codes.indexOf('EU') !== -1 && (window.gtlmAdmin.euCountries || []).indexOf(code) !== -1);
				if (hit) {
					var url = row.querySelector('.gtlm-geo-url');
					winner = {
						index: Number(row.getAttribute('data-index')) + 1,
						url: url ? url.value.trim() : ''
					};
					return true;
				}
				return false;
			});

			if (winner && winner.url) {
				testResult.className = 'gtlm-geo-test-result gtlm-geo-test-result--match';
				testResult.textContent =
					'→ ' + winner.url + ' (' + (i18n.geoRule || 'rule') + ' ' + winner.index + ')';
			} else if (fallback && fallback.value === 'block') {
				testResult.className = 'gtlm-geo-test-result gtlm-geo-test-result--block';
				testResult.textContent = '→ ' + (i18n.geo404 || '404 — blocked');
			} else {
				var main = document.getElementById('url');
				testResult.className = 'gtlm-geo-test-result';
				testResult.textContent =
					'→ ' + (main && main.value ? main.value : i18n.geoMainUrl || 'the main Destination URL');
			}
		}

		function refresh() {
			renumber();
			ruleRows().forEach(renderChips);
			renderWarnings();
			runPreview();
		}

		function toggleRules() {
			var wrapper = document.querySelector('.gtlm-field-geo-rules');
			if (wrapper) {
				wrapper.style.display = toggle && toggle.checked ? '' : 'none';
			}
		}

		if (toggle) {
			toggle.addEventListener('change', toggleRules);
			toggleRules();
		}

		if (addBtn && template) {
			addBtn.addEventListener('click', function () {
				var html = template.innerHTML.replace(/__INDEX__/g, String(ruleRows().length));
				var host = document.createElement('tbody');
				host.innerHTML = html.trim();
				var row = host.querySelector('tr');
				if (row) {
					rows.appendChild(row);
					refresh();
					var filter = row.querySelector('.gtlm-geo-filter');
					if (filter) {
						filter.focus();
					}
				}
			});
		}

		rows.addEventListener('click', function (e) {
			var target = e.target;
			var row = target.closest ? target.closest('tr.gtlm-geo-rule') : null;
			if (!row) {
				return;
			}

			// Quick pick: add a whole market in one click.
			if (target.classList.contains('gtlm-geo-preset')) {
				e.preventDefault();
				var codes = (target.getAttribute('data-codes') || '').split(',');
				var select = row.querySelector('.gtlm-geo-countries');
				if (select) {
					Array.prototype.forEach.call(select.options, function (o) {
						if (codes.indexOf(o.value) !== -1) {
							o.selected = true;
						}
					});
				}
				refresh();
				return;
			}

			// Chip acts as its own remove control.
			if (target.classList.contains('gtlm-geo-chip')) {
				e.preventDefault();
				var code = target.getAttribute('data-code');
				var sel = row.querySelector('.gtlm-geo-countries');
				if (sel) {
					Array.prototype.forEach.call(sel.options, function (o) {
						if (o.value === code) {
							o.selected = false;
						}
					});
				}
				refresh();
				return;
			}

			if (target.classList.contains('gtlm-geo-move-up') || target.classList.contains('gtlm-geo-move-down')) {
				e.preventDefault();
				var up = target.classList.contains('gtlm-geo-move-up');
				var sibling = up ? row.previousElementSibling : row.nextElementSibling;
				if (sibling && sibling.classList.contains('gtlm-geo-rule')) {
					if (up) {
						rows.insertBefore(row, sibling);
					} else {
						rows.insertBefore(sibling, row);
					}
					refresh();
					target.focus();
				}
				return;
			}

			if (target.classList.contains('gtlm-geo-remove-rule')) {
				e.preventDefault();
				// Keep one row so the table never becomes an empty shell.
				if (ruleRows().length === 1) {
					Array.prototype.forEach.call(row.querySelectorAll('input[type="url"]'), function (i) {
						i.value = '';
					});
					Array.prototype.forEach.call(row.querySelectorAll('option'), function (o) {
						o.selected = false;
					});
				} else {
					row.parentNode.removeChild(row);
				}
				refresh();
			}
		});

		// Filter the long country list down as the user types.
		rows.addEventListener('input', function (e) {
			if (e.target.classList.contains('gtlm-geo-filter')) {
				var row = e.target.closest('tr.gtlm-geo-rule');
				var select = row && row.querySelector('.gtlm-geo-countries');
				if (!select) {
					return;
				}
				var term = e.target.value.trim().toLowerCase();
				Array.prototype.forEach.call(select.options, function (o) {
					o.hidden = term !== '' && !o.selected && o.text.toLowerCase().indexOf(term) === -1;
				});
				return;
			}

			if (e.target.classList.contains('gtlm-geo-url')) {
				renderWarnings();
				runPreview();
			}
		});

		rows.addEventListener('change', function (e) {
			if (e.target.classList.contains('gtlm-geo-countries')) {
				refresh();
			}
		});

		if (testSelect) {
			testSelect.addEventListener('change', runPreview);
		}
		if (fallback) {
			fallback.addEventListener('change', runPreview);
		}

		refresh();
	})();

	// "Check Detection" on the settings screen.
	(function () {
		var btn = document.getElementById('gtlm-geo-check-btn');
		var out = document.getElementById('gtlm-geo-check-result');
		var sim = document.getElementById('gtlm-geo-check-simulate');

		if (!btn || !out || !window.gtlmAdmin) {
			return;
		}

		var i18n = window.gtlmAdmin.i18n || {};

		function row(cells, header) {
			var tr = document.createElement('tr');
			cells.forEach(function (text) {
				var cell = document.createElement(header ? 'th' : 'td');
				if (text instanceof Node) {
					cell.appendChild(text);
				} else {
					cell.textContent = text;
				}
				tr.appendChild(cell);
			});
			return tr;
		}

		function badge(text, ok) {
			var span = document.createElement('span');
			span.className = 'gtlm-status ' + (ok ? 'gtlm-status--active' : 'gtlm-status--inactive');
			span.textContent = text;
			return span;
		}

		btn.addEventListener('click', function () {
			btn.disabled = true;
			out.hidden = false;
			out.textContent = i18n.geoChecking || 'Checking…';

			var body = new URLSearchParams();
			body.append('action', 'gtlm_geo_check');
			body.append('nonce', btn.getAttribute('data-nonce') || '');
			body.append('simulate', sim ? sim.value : '');

			window
				.fetch(window.gtlmAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					btn.disabled = false;
					out.innerHTML = '';

					if (!res || !res.success) {
						out.appendChild(
							row([(res && res.data && res.data.message) || i18n.saveFailed || 'Check failed.'])
						);
						return;
					}

					var d = res.data;

					var summary = document.createElement('p');
					if (d.country) {
						summary.appendChild(badge(d.country, true));
						summary.appendChild(
							document.createTextNode(' ' + d.label + ' — ' + (i18n.geoVia || 'via') + ' ' + d.source)
						);
					} else {
						summary.appendChild(badge(i18n.geoNone || 'No country on this request', false));
						var why = document.createElement('span');
						why.className = 'description';
						why.textContent =
							' ' +
							(d.proxies && d.proxies.length
								? (i18n.geoProxyNoCountry || 'in front: %s — but it sent no country header').replace(
										'%s',
										d.proxies.join(', ')
								  )
								: i18n.geoNoProxy || 'nothing is proxying this site, so none is expected here');
						summary.appendChild(why);
					}
					out.appendChild(summary);

					// The part that works without a CDN: did detection resolve a
					// country we deliberately sent to ourselves?
					if (d.loopback) {
						var lp = document.createElement('p');
						if (d.loopback.ok && d.loopback.overridden) {
							// Best possible outcome: the edge rewrote our forged
							// header, proving both detection and un-forgeability.
							lp.appendChild(badge(i18n.geoSelfTestPass || 'Self-test passed', true));
							lp.appendChild(
								document.createTextNode(
									' ' +
										(i18n.geoSelfTestOverridden ||
											'sent %1$s as %2$s, and your CDN replaced it with %3$s. Detection works, and the country header cannot be forged by a visitor.')
											.replace('%1$s', d.loopback.sent)
											.replace('%2$s', d.loopback.header)
											.replace('%3$s', d.loopback.detected)
								)
							);
						} else if (d.loopback.ok) {
							lp.appendChild(badge(i18n.geoSelfTestPass || 'Self-test passed', true));
							lp.appendChild(
								document.createTextNode(
									' ' +
										(i18n.geoSelfTestOk ||
											'sent %1$s as %2$s to this site and the plugin detected %1$s. Detection works; it is only waiting on a CDN to supply real visitor countries.')
											.replace(/%1\$s/g, d.loopback.sent)
											.replace('%2$s', d.loopback.header)
								)
							);
						} else {
							lp.appendChild(badge(i18n.geoSelfTestFail || 'Self-test inconclusive', false));
							lp.appendChild(
								document.createTextNode(
									' ' +
										(d.loopback.message ||
											(i18n.geoSelfTestMismatch || 'sent %1$s but the plugin read %2$s.')
												.replace('%1$s', d.loopback.sent)
												.replace('%2$s', d.loopback.detected || '—'))
								)
							);
						}
						out.appendChild(lp);
					}

					if (!d.enabled) {
						var off = document.createElement('p');
						off.className = 'description';
						off.textContent =
							i18n.geoDisabled ||
							'Geolocation is currently disabled, so links ignore their rules. Tick "Enable Geolocation" and save.';
						out.appendChild(off);
					}

					var table = document.createElement('table');
					table.className = 'widefat striped gtlm-geo-check-table';
					var thead = document.createElement('thead');
					thead.appendChild(
						row(
							[
								i18n.geoSource || 'Source',
								i18n.geoVariable || 'Request variable',
								i18n.geoValue || 'Value'
							],
							true
						)
					);
					table.appendChild(thead);

					var tbody = document.createElement('tbody');
					d.sources.forEach(function (s) {
						var value = s.present
							? s.raw + (s.normalized ? '' : ' (' + (i18n.geoUnusable || 'not a usable country code') + ')')
							: '—';
						tbody.appendChild(row([s.source, s.key, value]));
					});
					table.appendChild(tbody);
					out.appendChild(table);

					if (d.simulate) {
						var p = document.createElement('p');
						if (d.simulate.valid) {
							p.appendChild(badge(d.simulate.code, true));
							p.appendChild(
								document.createTextNode(
									' ' + d.simulate.label + ' — ' + (i18n.geoUsable || 'valid in a rule')
								)
							);
						} else {
							p.appendChild(badge(d.simulate.input, false));
							p.appendChild(
								document.createTextNode(
									' ' + (i18n.geoNotUsable || 'is not a country code you can target')
								)
							);
						}
						out.appendChild(p);
					}
				})
				.catch(function () {
					btn.disabled = false;
					out.textContent = i18n.saveFailed || 'Check failed.';
				});
		});
	})();

	// Keyboard shortcut: "/" to focus search.
	document.addEventListener('keydown', function (e) {
		if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) {
			return;
		}
		var tag = (e.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
			return;
		}
		var searchInput = document.getElementById('gtlm-links-search-search-input');
		if (searchInput) {
			e.preventDefault();
			searchInput.focus();
		}
	});
})();
