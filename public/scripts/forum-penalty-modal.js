(function () {
    'use strict';

    var modal = document.getElementById('forumPenaltyModal');
    var form = document.getElementById('forumPenaltyForm');
    if (!modal || !form || !window.fetch || !window.FormData) {
        return;
    }

    var dialog = modal.querySelector('.forum-penalty-dialog');
    var title = modal.querySelector('#forumPenaltyTitle');
    var note = modal.querySelector('[data-penalty-note]');
    var alertBox = modal.querySelector('[data-penalty-alert]');
    var submitButton = modal.querySelector('.forum-penalty-modal-submit');
    var adjustFields = modal.querySelector('[data-penalty-adjust-fields]');
    var cancelFields = modal.querySelector('[data-penalty-cancel-fields]');
    var amountInput = form.querySelector('[name="amount"]');
    var reasonInput = form.querySelector('[name="reason"]');
    var cancelReasonInput = form.querySelector('[name="cancel_reason"]');
    var postInput = form.querySelector('[name="postid"]');
    var tokenInput = form.querySelector('[name="token"]');
    var operationInput = form.querySelector('[name="operation"]');
    var penaltyLogInput = form.querySelector('[name="penalty_log_id"]');
    var amountLabel = modal.querySelector('[data-penalty-amount-label]');
    var reasonLabel = modal.querySelector('[data-penalty-reason-label]');
    var lastTrigger = null;
    var previousOverflow = '';
    var closingTimer = null;

    function formatNumber(value) {
        var number = Number(value || 0);
        return number.toLocaleString('zh-CN', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }

    function selectedAdjustment() {
        var checked = form.querySelector('[name="adjustment"]:checked');
        return checked ? checked.value : 'deduct';
    }

    function setAlert(message, type) {
        alertBox.textContent = message || '';
        alertBox.className = 'forum-penalty-modal-alert' + (type ? ' is-' + type : '');
        alertBox.hidden = !message;
        alertBox.setAttribute('role', type === 'error' ? 'alert' : 'status');
    }

    function idleSubmitLabel() {
        if (operationInput.value === 'cancel') {
            return '确认取消扣分';
        }
        return selectedAdjustment() === 'add' ? '确认增加' : '确认扣除';
    }

    function setLoading(loading, label) {
        submitButton.disabled = loading;
        submitButton.textContent = label || (loading ? '处理中…' : idleSubmitLabel());
        form.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function setFieldDisabled(container, disabled) {
        Array.prototype.forEach.call(container.querySelectorAll('input, textarea'), function (field) {
            field.disabled = disabled;
        });
    }

    function syncAdjustmentLabels() {
        if (operationInput.value !== 'adjust') {
            return;
        }
        var isAddition = selectedAdjustment() === 'add';
        modal.setAttribute('data-adjustment', isAddition ? 'add' : 'deduct');
        title.textContent = isAddition ? '增加论坛积分' : '论坛违规扣分';
        note.textContent = (isAddition ? '增加' : '扣除') + '结果和原因会公开显示在对应帖子下方，并通知该用户。';
        amountLabel.textContent = isAddition ? '增加数量' : '扣除数量';
        reasonLabel.textContent = isAddition ? '增加原因' : '扣分原因';
        setLoading(false);
    }

    function setMode(data) {
        var isCancellation = data.mode === 'cancel';
        operationInput.value = isCancellation ? 'cancel' : 'adjust';
        adjustFields.hidden = isCancellation;
        cancelFields.hidden = !isCancellation;
        setFieldDisabled(adjustFields, isCancellation);
        setFieldDisabled(cancelFields, !isCancellation);
        amountInput.required = !isCancellation;
        reasonInput.required = !isCancellation;
        cancelReasonInput.required = isCancellation;
        penaltyLogInput.value = isCancellation && data.penalty ? data.penalty.id : '';
        if (isCancellation) {
            modal.setAttribute('data-adjustment', 'cancel');
            title.textContent = '取消扣分';
            note.textContent = '取消后会返还原扣除数额；取消原因、操作人和时间会显示在原扣分记录下方。';
            modal.querySelector('[data-penalty-original]').textContent = '原记录：扣除 ' + formatNumber(data.penalty.amount) + ' ' + data.penalty.field_label;
            modal.querySelector('[data-penalty-original-reason]').textContent = '原扣分原因：' + data.penalty.reason;
        } else {
            syncAdjustmentLabels();
        }
    }

    function openModal(trigger, url) {
        if (closingTimer) {
            window.clearTimeout(closingTimer);
            closingTimer = null;
        }
        lastTrigger = trigger;
        form.reset();
        var preferredAdjustment = trigger.getAttribute('data-penalty-default-adjustment');
        if (preferredAdjustment === 'add' || preferredAdjustment === 'deduct') {
            var preferredInput = form.querySelector('[name="adjustment"][value="' + preferredAdjustment + '"]');
            if (preferredInput) {
                preferredInput.checked = true;
            }
        }
        operationInput.value = 'adjust';
        penaltyLogInput.value = '';
        setAlert('正在读取用户余额…', 'loading');
        setLoading(true, '加载中…');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.focus();

        var requestUrl = new URL(url, window.location.href);
        requestUrl.searchParams.set('format', 'json');
        fetch(requestUrl.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok || !json.ok) {
                        throw new Error(json.message || '无法读取积分调整信息。');
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
                setMode(data);
                setAlert('', '');
                setLoading(false);
                (data.mode === 'cancel' ? cancelReasonInput : amountInput).focus();
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
        if (lastTrigger && document.documentElement.contains(lastTrigger)) {
            lastTrigger.focus();
        }
    }

    function createCancelButton(record) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'forum-post-penalty-cancel';
        button.setAttribute('data-penalty-cancel', '');
        button.setAttribute('data-penalty-cancel-url', 'forums.php?action=penalizepost&format=json&operation=cancel&postid=' + record.post_id + '&penalty_log_id=' + record.id);
        button.textContent = '取消扣分';
        return button;
    }

    function createPenaltyNotice(record) {
        var isAddition = record.action === 'add';
        var article = document.createElement('article');
        article.className = 'forum-post-penalty' + (isAddition ? ' is-addition' : '');
        article.setAttribute('data-penalty-id', record.id);

        var icon = document.createElement('span');
        icon.className = 'forum-post-penalty-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 3 3.8 6.4v5.1c0 5.1 3.5 8.5 8.2 9.5 4.7-1 8.2-4.4 8.2-9.5V6.4L12 3Z"></path><path d="M8 12h8"></path>' + (isAddition ? '<path d="M12 8v8"></path>' : '') + '</svg>';

        var content = document.createElement('span');
        content.className = 'forum-post-penalty-content';
        var heading = document.createElement('span');
        heading.className = 'forum-post-penalty-heading';
        var headingText = document.createElement('strong');
        headingText.textContent = '该帖已被' + (isAddition ? '增加 ' : '扣除 ') + formatNumber(record.amount) + ' ' + record.field_label;
        heading.appendChild(headingText);
        if (!isAddition) {
            heading.appendChild(createCancelButton(record));
        }
        var reason = document.createElement('span');
        reason.className = 'forum-post-penalty-reason';
        reason.textContent = '原因：' + record.reason;
        var meta = document.createElement('small');
        meta.textContent = '操作人：' + record.operator + (record.created_at ? ' · ' + record.created_at : '');
        content.appendChild(heading);
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

    function applyCancellation(cancellation) {
        var article = document.querySelector('.forum-post-penalty[data-penalty-id="' + cancellation.penalty_id + '"]');
        if (!article) {
            return;
        }
        article.classList.add('is-cancelled');
        var cancelButton = article.querySelector('[data-penalty-cancel]');
        if (cancelButton) {
            cancelButton.remove();
        }
        var headingText = article.querySelector('.forum-post-penalty-heading strong');
        if (headingText && !headingText.querySelector('.forum-post-penalty-cancelled-label')) {
            var cancelledLabel = document.createElement('span');
            cancelledLabel.className = 'forum-post-penalty-cancelled-label';
            cancelledLabel.textContent = '(已被取消扣除)';
            headingText.appendChild(cancelledLabel);
        }
        if (article.querySelector('.forum-post-penalty-cancellation')) {
            return;
        }
        var cancellationBlock = document.createElement('span');
        cancellationBlock.className = 'forum-post-penalty-cancellation';
        var reason = document.createElement('span');
        reason.textContent = '取消原因：' + cancellation.reason;
        var meta = document.createElement('small');
        meta.textContent = '取消操作人：' + cancellation.operator + (cancellation.created_at ? ' · ' + cancellation.created_at : '');
        cancellationBlock.appendChild(reason);
        cancellationBlock.appendChild(meta);
        article.querySelector('.forum-post-penalty-content').appendChild(cancellationBlock);
    }

    document.addEventListener('click', function (event) {
        var adjustTrigger = event.target.closest('a.forum-penalty-link');
        if (adjustTrigger) {
            event.preventDefault();
            openModal(adjustTrigger, adjustTrigger.href);
            return;
        }
        var cancelTrigger = event.target.closest('[data-penalty-cancel]');
        if (cancelTrigger) {
            event.preventDefault();
            openModal(cancelTrigger, cancelTrigger.getAttribute('data-penalty-cancel-url'));
            return;
        }
        if (!modal.hidden && event.target.closest('[data-penalty-close]')) {
            event.preventDefault();
            closeModal();
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.name === 'adjustment') {
            syncAdjustmentLabels();
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
        var focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled])')).filter(function (element) {
            return element.offsetParent !== null;
        });
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
                        throw new Error(json.message || '操作失败，请稍后重试。');
                    }
                    return json;
                });
            })
            .then(function (json) {
                if (operationInput.value === 'cancel') {
                    applyCancellation(json.cancellation);
                } else {
                    appendPenaltyNotice(json.penalty);
                }
                setAlert(json.message || '操作成功，记录已更新。', 'success');
                setLoading(true, '操作成功');
                closingTimer = window.setTimeout(closeModal, 900);
            })
            .catch(function (error) {
                setAlert(error.message || '操作失败，请稍后重试。', 'error');
                setLoading(false);
                (operationInput.value === 'cancel' ? cancelReasonInput : reasonInput).focus();
            });
    });
})();
