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

	function buildQuickEditRow(tr, data) {
		removeQuickEditor();

		var colCount = tr.children.length;
		var quickTr = document.createElement('tr');
		quickTr.className = 'gtlm-quick-edit-row inline-edit-row';

		var td = document.createElement('td');
		td.setAttribute('colspan', colCount);

		var wrap = document.createElement('div');
		wrap.className = 'gtlm-quick-edit-wrap';

		var urlLabel = document.createElement('label');
		urlLabel.textContent = 'Destination URL ';
		var urlInput = document.createElement('input');
		urlInput.type = 'url';
		urlInput.className = 'gtlm-quick-url';
		urlInput.value = data.url;
		urlLabel.appendChild(urlInput);

		var typeLabel = document.createElement('label');
		typeLabel.textContent = 'Type ';
		var typeSelect = document.createElement('select');
		typeSelect.className = 'gtlm-quick-type';
		['301', '302', '307'].forEach(function (val) {
			var opt = document.createElement('option');
			opt.value = val;
			opt.textContent = val;
			typeSelect.appendChild(opt);
		});
		typeLabel.appendChild(typeSelect);

		var saveBtn = document.createElement('button');
		saveBtn.type = 'button';
		saveBtn.className = 'button button-primary gtlm-quick-save';
		saveBtn.textContent = 'Save';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'button gtlm-quick-cancel';
		cancelBtn.textContent = 'Cancel';

		var spinner = document.createElement('span');
		spinner.className = 'spinner';
		spinner.style.cssText = 'float:none;margin:0 0 0 8px;';

		var message = document.createElement('span');
		message.className = 'gtlm-quick-message';
		message.style.marginLeft = '10px';

		wrap.appendChild(urlLabel);
		wrap.appendChild(document.createTextNode(' '));
		wrap.appendChild(typeLabel);
		wrap.appendChild(document.createTextNode(' '));
		wrap.appendChild(saveBtn);
		wrap.appendChild(document.createTextNode(' '));
		wrap.appendChild(cancelBtn);
		wrap.appendChild(document.createTextNode(' '));
		wrap.appendChild(spinner);
		wrap.appendChild(message);
		td.appendChild(wrap);
		quickTr.appendChild(td);

		tr.parentNode.insertBefore(quickTr, tr.nextSibling);
		typeSelect.value = String(data.redirectType);

		quickTr.querySelector('.gtlm-quick-cancel').addEventListener('click', function () {
			removeQuickEditor();
		});

		quickTr.querySelector('.gtlm-quick-save').addEventListener('click', function () {
			var urlInput = quickTr.querySelector('.gtlm-quick-url');
			var typeInput = quickTr.querySelector('.gtlm-quick-type');
			var spinner = quickTr.querySelector('.spinner');
			var message = quickTr.querySelector('.gtlm-quick-message');
			var formData = new window.FormData();

			message.textContent = '';
			spinner.classList.add('is-active');

			formData.append('action', 'gt_link_quick_edit');
			formData.append('nonce', window.gtlmAdmin.quickEditNonce);
			formData.append('link_id', data.linkId);
			formData.append('url', urlInput.value);
			formData.append('redirect_type', typeInput.value);

			window.fetch(window.gtlmAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
				.then(function (res) { return res.json(); })
				.then(function (json) {
					spinner.classList.remove('is-active');
					if (!json || !json.success) {
						message.textContent = window.gtlmAdmin.i18n.saveFailed;
						message.style.color = '#b32d2e';
						return;
					}

					var dataOut = json.data || {};
					var destCell = tr.querySelector('td.column-url a');
					var typeCell = tr.querySelector('td.column-redirect_type');
					if (destCell && dataOut.url) {
						destCell.href = dataOut.url;
						destCell.textContent = dataOut.url;
					}
					if (typeCell && dataOut.redirect_type) {
						typeCell.textContent = dataOut.redirect_type;
					}

					message.textContent = window.gtlmAdmin.i18n.saved;
					message.style.color = '#008a20';
					window.setTimeout(removeQuickEditor, 600);
				})
				.catch(function () {
					spinner.classList.remove('is-active');
					message.textContent = window.gtlmAdmin.i18n.saveFailed;
					message.style.color = '#b32d2e';
				});
		});
	}

	document.addEventListener('click', function (event) {
		var quickLink = event.target.closest('.gt-link-quick-edit');
		if (quickLink) {
			event.preventDefault();
			var tr = quickLink.closest('tr');
			if (!tr) {
				return;
			}
			buildQuickEditRow(tr, {
				linkId: quickLink.getAttribute('data-link-id'),
				url: quickLink.getAttribute('data-url') || '',
				redirectType: quickLink.getAttribute('data-redirect-type') || '301'
			});
			return;
		}

		var copyLink = event.target.closest('.gt-link-copy-url');
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
		preview.textContent = window.location.origin + '/' + prefix + '/' + slug;
	}

	if (nameField && slugField) {
		nameField.addEventListener('input', function () {
			if (!slugTouched || !slugField.value.trim()) {
				slugField.value = slugify(nameField.value);
			}
			updatePreview();
		});
		slugField.addEventListener('input', function () {
			slugTouched = true;
			slugField.value = slugify(slugField.value);
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
})();
