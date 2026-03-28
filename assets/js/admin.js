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
