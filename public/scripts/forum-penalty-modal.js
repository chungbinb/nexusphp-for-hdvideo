(function () {
    'use strict';

    var modal = document.getElementById('forumPenaltyModal');
    var form = document.getElementById('forumPenaltyForm');
    if (!modal || !form || !window.fetch || !window.FormData) {
        return;
    }

    var dialog = modal.querySelector('.forum-penalty-dialog');
    var alertBox = modal.querySelector('[data-penalty-alert]');
    var submitButton = modal.querySelector('.forum-penalty-modal-submit');
    var amountInput = form.querySelector('[name="amount"]');
    var reasonInput = form.querySelector('[name="reason"]');
    var postInput = form.querySelector('[name="postid"]');
    var tokenInput = form.querySelector('[name="token"]');
    var lastTrigger = null;
    var previousOverflow = '';
    var closingTimer = null;

    function formatNumber(value) {
        var number = Number(value || 0);
        return number.toLocaleString('zh-CN', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }

    function setAlert(message, type) {
        alertBox.textContent = message || '';
        alertBox.className = 'forum-penalty-modal-alert' + (type ? ' is-' + type : '');
        alertBox.hidden = !message;
        alertBox.setAttribute('role', type === 'error' ? 'alert' : 'status');
    }

    function setLoading(loading, label) {
        submitButton.disabled = loading;
        submitButton.textContent = label || (loading ? '处理中…' : '确认扣除');
        form.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function openModal(trigger) {
        if (closingTimer) {
            window.clearTimeout(closingTimer);
            closingTimer = null;
        }
        lastTrigger = trigger;
        form.reset();
        setAlert('正在读取用户余额…', 'loading');
        setLoading(true, '加载中…');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.focus();

        var url = new URL(trigger.href, window.location.href);
        url.searchParams.set('format', 'json');
        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok || !json.ok) {
                        throw new Error(json.message || '无法读取扣分信息。');
                    }
                    return json.data;
                });
            })
            .then(function (data) {
                postInput.value = data.post_id;
                tokenInput.value = data.token;
                modal.querySelector('[data-penalty-user]').textContent = data.username + '（UID ' + data.user_id + '）';
                modal.querySelector('[data-penalty-post]').textContent = '#' + data.post_id + ' · ' + data.subject;
                modal.querySelector('[data-penalty-bonus]').textContent = formatNumber(data.seedbonus);
                modal.querySelector('[data-penalty-points]').textContent = formatNumber(data.seed_points);
                setAlert('', '');
                setLoading(false);
                amountInput.focus();
            })
            .catch(function (error) {
                setAlert(error.message || '加载失败，请稍后重试。', 'error');
                setLoading(true, '无法提交');
            });
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousOverflow;
        setLoading(false);
        if (lastTrigger) {
            lastTrigger.focus();
        }
    }

    function createPenaltyNotice(record) {
        var article = document.createElement('article');
        article.className = 'forum-post-penalty';
        article.setAttribute('data-penalty-id', record.id);

        var icon = document.createElement('span');
        icon.className = 'forum-post-penalty-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 3 3.8 6.4v5.1c0 5.1 3.5 8.5 8.2 9.5 4.7-1 8.2-4.4 8.2-9.5V6.4L12 3Z"></path><path d="M8 12h8"></path></svg>';

        var content = document.createElement('span');
        content.className = 'forum-post-penalty-content';
        var title = document.createElement('strong');
        title.textContent = '该帖已被扣除 ' + formatNumber(record.amount) + ' ' + record.field_label;
        var reason = document.createElement('span');
        reason.className = 'forum-post-penalty-reason';
        reason.textContent = '原因：' + record.reason;
        var meta = document.createElement('small');
        meta.textContent = '操作人：' + record.operator + (record.created_at ? ' · ' + record.created_at : '');
        content.appendChild(title);
        content.appendChild(reason);
        content.appendChild(meta);
        article.appendChild(icon);
        article.appendChild(content);
        return article;
    }

    function appendPenaltyNotice(record) {
        var container = document.getElementById('forum-penalties-' + record.post_id);
        if (!container || container.querySelector('[data-penalty-id="' + record.id + '"]')) {
            return;
        }
        container.insertBefore(createPenaltyNotice(record), container.firstChild);
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('a.forum-penalty-link');
        if (trigger) {
            event.preventDefault();
            openModal(trigger);
            return;
        }
        if (!modal.hidden && event.target.closest('[data-penalty-close]')) {
            event.preventDefault();
            closeModal();
        }
    });

    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled])'));
        if (!focusable.length) {
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        setAlert('', '');
        setLoading(true);
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok || !json.ok) {
                        throw new Error(json.message || '扣分失败，请稍后重试。');
                    }
                    return json;
                });
            })
            .then(function (json) {
                appendPenaltyNotice(json.penalty);
                setAlert(json.message || '扣分成功，原因已显示在对应帖子下方。', 'success');
                setLoading(true, '扣分成功');
                closingTimer = window.setTimeout(closeModal, 900);
            })
            .catch(function (error) {
                setAlert(error.message || '扣分失败，请稍后重试。', 'error');
                setLoading(false);
                reasonInput.focus();
            });
    });
})();
