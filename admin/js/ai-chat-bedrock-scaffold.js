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

	// Neither button may be pressed while the other is working: a new plan in the middle of
	// creating drafts emptied the table the drafts were being written from.
	function busy(on) {
		['aicfab-scaffold-plan', 'aicfab-scaffold-create'].forEach(function (id) {
			var button = document.getElementById(id);
			if (button) {
				button.disabled = on;
			}
		});
	}

	function planPages() {
		var description = document.getElementById('aicfab-scaffold-description').value.trim();
		if (!description) {
			text('aicfab-scaffold-status', settings.i18n.describeFirst);
			return;
		}

		busy(true);
		text('aicfab-scaffold-status', settings.i18n.thinking);

		post('aicfab_scaffold_plan', { description: description }).catch(function () {
			return { success: false };
		}).then(function (response) {
			busy(false);
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
			// Creating the drafts is the next step, so it becomes the one primary button.
			document.getElementById('aicfab-scaffold-plan').classList.remove('button-primary');
			text('aicfab-scaffold-status', response.data.message || '');
			text('aicfab-scaffold-progress', '');
		});
	}

	var counts = { created: 0, skipped: 0, failed: 0 };

	function createNext(rows, index, description, done) {
		if (index >= rows.length) {
			done();
			return;
		}

		var tr = rows[index];
		var include = tr.querySelector('.aicfab-scaffold-include');
		var result = tr.querySelector('.aicfab-scaffold-result');
		if (!include.checked) {
			counts.skipped++;
			result.textContent = settings.i18n.skippedByYou;
			createNext(rows, index + 1, description, done);
			return;
		}

		result.textContent = settings.i18n.writing;
		post('aicfab_scaffold_create', {
			description: description,
			title: tr.querySelector('.aicfab-scaffold-title').value,
			purpose: tr.querySelector('.aicfab-scaffold-purpose').textContent
		}).catch(function () {
			return { success: false };
		}).then(function (response) {
			if (!response.success) {
				counts.failed++;
				result.textContent = (response.data && response.data.message) || settings.i18n.unexpected;
			} else if (response.data.skipped) {
				counts.skipped++;
				result.textContent = response.data.reason;
			} else {
				counts.created++;
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
		var description = document.getElementById('aicfab-scaffold-description').value.trim();
		var rows = Array.prototype.slice.call(document.querySelectorAll('#aicfab-scaffold-plan-table tbody tr'));
		if (!rows.length) {
			return;
		}
		counts = { created: 0, skipped: 0, failed: 0 };
		busy(true);
		createNext(rows, 0, description, function () {
			busy(false);
			// Say what happened. This used to report every page as created, even when some
			// had failed or been left out.
			text('aicfab-scaffold-progress', settings.i18n.finished
				.replace('%1$d', counts.created)
				.replace('%2$d', counts.skipped)
				.replace('%3$d', counts.failed));
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
