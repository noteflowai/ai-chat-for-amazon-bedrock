/**
 * Site scaffold screen.
 *
 * Pages are created one request at a time so progress is visible and a failure part way
 * through leaves the drafts already made intact.
 */
(function () {
	'use strict';

	var settings = window.aicfabScaffold || {};

	function text(id, value) {
		var node = document.getElementById(id);
		if (node) {
			node.textContent = value;
		}
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', settings.nonce || '');
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json().catch(function () {
				return { success: false, data: { message: settings.i18n.unexpected } };
			});
		});
	}

	function row(page) {
		var tr = document.createElement('tr');

		var include = document.createElement('td');
		include.className = 'check-column';
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.checked = true;
		box.className = 'aicfab-scaffold-include';
		include.appendChild(box);

		var title = document.createElement('td');
		var field = document.createElement('input');
		field.type = 'text';
		field.className = 'regular-text aicfab-scaffold-title';
		field.value = page.title;
		title.appendChild(field);

		// Every row holds the same two controls, so the names have to carry the page title
		// or a screen reader reads four identical "Page title" fields. Kept in step with
		// the field, since the title is editable.
		function label() {
			var current = field.value.trim() || page.title;
			box.setAttribute('aria-label', settings.i18n.includeLabel + ' ' + current);
			field.setAttribute('aria-label', settings.i18n.titleLabel + ': ' + current);
		}
		label();
		field.addEventListener('input', label);

		var purpose = document.createElement('td');
		purpose.textContent = page.purpose || '';
		purpose.className = 'aicfab-scaffold-purpose';

		var result = document.createElement('td');
		result.className = 'aicfab-scaffold-result';

		tr.appendChild(include);
		tr.appendChild(title);
		tr.appendChild(purpose);
		tr.appendChild(result);
		return tr;
	}

	function planPages() {
		var description = document.getElementById('aicfab-scaffold-description').value.trim();
		if (!description) {
			text('aicfab-scaffold-status', settings.i18n.describeFirst);
			return;
		}

		var button = document.getElementById('aicfab-scaffold-plan');
		button.disabled = true;
		text('aicfab-scaffold-status', settings.i18n.thinking);

		post('aicfab_scaffold_plan', { description: description }).then(function (response) {
			button.disabled = false;
			if (!response.success) {
				text('aicfab-scaffold-status', (response.data && response.data.message) || settings.i18n.unexpected);
				return;
			}
			var body = document.querySelector('#aicfab-scaffold-plan-table tbody');
			body.innerHTML = '';
			response.data.pages.forEach(function (page) {
				body.appendChild(row(page));
			});
			document.getElementById('aicfab-scaffold-plan-wrap').hidden = false;
			text('aicfab-scaffold-status', settings.i18n.planReady.replace('%d', response.data.pages.length));
		});
	}

	function createNext(rows, index, description, done) {
		if (index >= rows.length) {
			done();
			return;
		}

		var tr = rows[index];
		var include = tr.querySelector('.aicfab-scaffold-include');
		var result = tr.querySelector('.aicfab-scaffold-result');
		if (!include.checked) {
			result.textContent = settings.i18n.skippedByYou;
			createNext(rows, index + 1, description, done);
			return;
		}

		result.textContent = settings.i18n.writing;
		post('aicfab_scaffold_create', {
			description: description,
			title: tr.querySelector('.aicfab-scaffold-title').value,
			purpose: tr.querySelector('.aicfab-scaffold-purpose').textContent
		}).then(function (response) {
			if (!response.success) {
				result.textContent = (response.data && response.data.message) || settings.i18n.unexpected;
			} else if (response.data.skipped) {
				result.textContent = response.data.reason;
			} else {
				result.textContent = '';
				var link = document.createElement('a');
				link.href = response.data.edit;
				link.textContent = settings.i18n.editDraft;
				result.appendChild(link);
			}
			text('aicfab-scaffold-progress', settings.i18n.progress.replace('%1$d', index + 1).replace('%2$d', rows.length));
			createNext(rows, index + 1, description, done);
		});
	}

	function createDrafts() {
		var button = document.getElementById('aicfab-scaffold-create');
		var description = document.getElementById('aicfab-scaffold-description').value.trim();
		var rows = Array.prototype.slice.call(document.querySelectorAll('#aicfab-scaffold-plan-table tbody tr'));
		if (!rows.length) {
			return;
		}
		button.disabled = true;
		createNext(rows, 0, description, function () {
			button.disabled = false;
			text('aicfab-scaffold-progress', settings.i18n.finished);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var plan = document.getElementById('aicfab-scaffold-plan');
		var create = document.getElementById('aicfab-scaffold-create');
		if (plan) {
			plan.addEventListener('click', planPages);
		}
		if (create) {
			create.addEventListener('click', createDrafts);
		}
	});
})();
