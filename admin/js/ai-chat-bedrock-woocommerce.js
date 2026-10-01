/**
 * Product assistant on the WooCommerce product edit screen.
 *
 * The draft is shown for review first. Inserting it puts it in the editor, and nothing is
 * stored until the product is updated.
 */
(function () {
    'use strict';

    const params = window.aicfabProductAssistant || {};
    const i18n = params.i18n || {};
    const box = document.querySelector('.aicfab-product-assistant');
    if (!box || !window.wp || !window.wp.apiFetch || !params.path) {
        return;
    }

    const status = box.querySelector('.aicfab-pa-status');
    const result = box.querySelector('.aicfab-pa-result');
    const output = box.querySelector('.aicfab-pa-output');
    const insert = box.querySelector('.aicfab-pa-insert');
    const copy = box.querySelector('.aicfab-pa-copy');
    const buttons = Array.prototype.slice.call(box.querySelectorAll('.aicfab-pa-run'));
    // The short description is the excerpt editor and the description is the main one.
    const targets = { short_description: 'excerpt', description: 'content' };
    let target = '';

    function say(text) {
        status.textContent = text || '';
    }

    function editorValue(id) {
        const editor = window.tinymce && window.tinymce.get(id);
        if (editor && !editor.isHidden()) {
            return editor.getContent();
        }
        const area = document.getElementById(id);
        return area ? area.value : '';
    }

    function setEditor(id, html) {
        const editor = window.tinymce && window.tinymce.get(id);
        if (editor) {
            editor.setContent(html);
            editor.save();
            editor.fire('change');
        }
        const area = document.getElementById(id);
        if (area) {
            area.value = html;
            area.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            const task = button.getAttribute('data-task');
            buttons.forEach(function (other) {
                other.disabled = true;
            });
            say(i18n.working);
            window.wp.apiFetch({
                path: params.path,
                method: 'POST',
                data: { product: Number(box.getAttribute('data-product')) || 0, task: task }
            }).then(function (response) {
                output.value = response && response.html ? String(response.html) : '';
                target = targets[task] || '';
                result.hidden = false;
                if (target) {
                    insert.textContent = 'excerpt' === target ? i18n.insert_short : i18n.insert_long;
                    insert.hidden = false;
                } else {
                    insert.hidden = true;
                }
                say(i18n.done);
                output.focus();
            }).catch(function (error) {
                say(error && error.message ? error.message : i18n.error);
            }).finally(function () {
                buttons.forEach(function (other) {
                    other.disabled = false;
                });
            });
        });
    });

    insert.addEventListener('click', function () {
        if (!target || !output.value) {
            return;
        }
        if ('' !== editorValue(target).trim() && !window.confirm(i18n.replace_confirm)) {
            return;
        }
        setEditor(target, output.value);
        say(i18n.inserted);
    });

    copy.addEventListener('click', function () {
        const done = function () {
            say(i18n.copied);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(output.value).then(done, function () {
                output.select();
            });
            return;
        }
        output.select();
        if (document.execCommand && document.execCommand('copy')) {
            done();
        }
    });
}());
