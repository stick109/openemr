(function () {
    'use strict';

    function initPanel(panel) {
        if (!panel || panel.dataset.agentPanelInitialized === 'true') {
            return;
        }
        panel.dataset.agentPanelInitialized = 'true';

        var apiUrl = panel.dataset.apiUrl || '';
        var apiCsrfToken = panel.dataset.apiCsrfToken || '';
        var conversationId = 'chart-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        var statusNode = panel.querySelector('.js-agent-status');
        var outputNode = panel.querySelector('.js-agent-output');
        var promptPreviewNode = panel.querySelector('.js-agent-prompt-preview');
        var buttons = Array.prototype.slice.call(panel.querySelectorAll('.js-agent-intent'));
        var intentLabels = new Map();
        var intentPrompts = new Map();
        var messages = {
            loading: panel.dataset.loadingLabel || 'Loading...',
            requestFailed: panel.dataset.requestFailedLabel || 'Request failed.',
            noResponse: panel.dataset.noResponseLabel || 'No agent response was returned.',
            unavailable: panel.dataset.unavailableLabel || 'Agent endpoint is unavailable.',
            missing: panel.dataset.missingLabel || 'Missing or uncertain',
            evidence: panel.dataset.evidenceLabel || 'Checked evidence',
            none: panel.dataset.noneLabel || 'None',
            source: panel.dataset.sourceLabel || 'source',
            sourceAria: panel.dataset.sourceAriaLabel || 'Show source',
            sourcePrompt: panel.dataset.sourcePromptText || ''
        };

        buttons.forEach(function (button) {
            if (!button.dataset.intentId) {
                return;
            }
            intentLabels.set(button.dataset.intentId, button.textContent.trim());
            intentPrompts.set(button.dataset.intentId, button.dataset.promptText || '');
        });

        function clearNode(node) {
            while (node.firstChild) {
                node.removeChild(node.firstChild);
            }
        }

        function appendTextElement(parent, tagName, className, text) {
            var element = document.createElement(tagName);
            if (className) {
                element.className = className;
            }
            element.textContent = text;
            parent.appendChild(element);
            return element;
        }

        function appendCitationLinks(parent, citationIds) {
            if (!Array.isArray(citationIds) || citationIds.length === 0) {
                return;
            }

            var sourceContainer = document.createElement('span');
            sourceContainer.className = 'agent-panel__source-list text-muted';
            sourceContainer.appendChild(document.createTextNode(' ('));
            citationIds.forEach(function (citationId, index) {
                var sourceLink = document.createElement('button');
                sourceLink.type = 'button';
                sourceLink.className = 'agent-panel__source-link btn btn-link p-0 align-baseline';
                sourceLink.dataset.sourceId = citationId;
                sourceLink.textContent = citationIds.length === 1
                    ? messages.source
                    : messages.source + ' ' + (index + 1);
                sourceLink.setAttribute('aria-label', messages.sourceAria + ': ' + citationId);
                sourceLink.addEventListener('click', function () {
                    requestIntent('show_source', citationId);
                });
                if (index > 0) {
                    sourceContainer.appendChild(document.createTextNode(', '));
                }
                sourceContainer.appendChild(sourceLink);
            });
            sourceContainer.appendChild(document.createTextNode(')'));
            parent.appendChild(sourceContainer);
        }

        function shouldShowCertainty(certainty) {
            return certainty && certainty !== 'source_record' && certainty !== 'supported';
        }

        function setButtonsDisabled(disabled) {
            Array.prototype.slice.call(panel.querySelectorAll('button')).forEach(function (button) {
                if (disabled) {
                    button.dataset.agentWasDisabled = button.disabled ? 'true' : 'false';
                    button.disabled = true;
                    return;
                }

                if (button.dataset.agentWasDisabled !== 'true') {
                    button.disabled = false;
                }
                delete button.dataset.agentWasDisabled;
            });
        }

        function setLoadingState(loading) {
            panel.classList.toggle('is-agent-loading', loading);
            document.body.classList.toggle('agent-loading-cursor', loading);
            panel.setAttribute('aria-busy', loading ? 'true' : 'false');
            outputNode.hidden = loading;
            if (loading) {
                clearNode(outputNode);
            }
            setButtonsDisabled(loading);
        }

        function showOutput() {
            outputNode.hidden = false;
        }

        function renderValidationErrors(errors) {
            showOutput();
            clearNode(outputNode);
            var alert = appendTextElement(outputNode, 'div', 'alert alert-warning py-2 mb-0', messages.requestFailed);
            Object.keys(errors).forEach(function (field) {
                (errors[field] || []).forEach(function (message) {
                    appendTextElement(alert, 'div', '', field + ': ' + message);
                });
            });
        }

        function renderError(message) {
            showOutput();
            clearNode(outputNode);
            appendTextElement(outputNode, 'div', 'alert alert-danger py-2 mb-0', message);
        }

        function renderAnswer(data) {
            showOutput();
            clearNode(outputNode);
            if (!data || !data.answer) {
                appendTextElement(outputNode, 'div', 'text-muted', messages.noResponse);
                return;
            }

            (data.answer.answer_blocks || []).forEach(function (block) {
                appendTextElement(outputNode, 'div', 'agent-panel__heading', block.heading || data.button_label || '');
                var claims = Array.isArray(block.claims) ? block.claims : [];
                var list = document.createElement('ul');
                list.className = 'agent-panel__claim-list';
                claims.forEach(function (claim) {
                    var item = document.createElement('li');
                    appendTextElement(item, 'span', '', claim.text || '');
                    if (shouldShowCertainty(claim.certainty)) {
                        appendTextElement(item, 'span', 'text-muted ml-1', '(' + claim.certainty + ')');
                    }
                    appendCitationLinks(item, claim.citation_ids);
                    list.appendChild(item);
                });
                outputNode.appendChild(list);
            });

            if (Array.isArray(data.answer.missing_or_uncertain) && data.answer.missing_or_uncertain.length > 0) {
                appendTextElement(outputNode, 'div', 'small font-weight-bold mb-1', messages.missing);
                var missingList = document.createElement('ul');
                missingList.className = 'agent-panel__claim-list';
                data.answer.missing_or_uncertain.forEach(function (item) {
                    var missingItem = document.createElement('li');
                    appendTextElement(missingItem, 'span', '', item.text || '');
                    appendCitationLinks(missingItem, item.citation_ids);
                    missingList.appendChild(missingItem);
                });
                outputNode.appendChild(missingList);
            }

            var evidence = Array.isArray(data.checked_evidence) && data.checked_evidence.length > 0
                ? data.checked_evidence.join(', ')
                : messages.none;
            appendTextElement(outputNode, 'div', 'small text-muted', messages.evidence + ': ' + evidence);
        }

        async function requestIntent(intentId, sourceId) {
            if (promptPreviewNode) {
                promptPreviewNode.value = intentId === 'show_source' && sourceId
                    ? messages.sourcePrompt
                    : intentPrompts.get(intentId) || '';
            }

            setLoadingState(true);
            statusNode.textContent = messages.loading;

            try {
                if (window.top && typeof window.top.restoreSession === 'function') {
                    window.top.restoreSession();
                }

                var payload = {
                    intent_id: intentId,
                    conversation_id: conversationId,
                    active_patient_context: 'server-session'
                };
                if (sourceId) {
                    payload.source_id = sourceId;
                }

                var response = await fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        APICSRFTOKEN: apiCsrfToken,
                        Accept: 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                var body = await response.json();

                if (body.validationErrors && Object.keys(body.validationErrors).length > 0) {
                    renderValidationErrors(body.validationErrors);
                    statusNode.textContent = messages.requestFailed;
                    return;
                }

                if (!response.ok) {
                    renderError(messages.requestFailed);
                    statusNode.textContent = messages.requestFailed;
                    return;
                }

                renderAnswer(body.data);
                statusNode.textContent = body.data && body.data.button_label ? body.data.button_label : '';
            } catch (error) {
                renderError(messages.unavailable);
                statusNode.textContent = messages.requestFailed;
            } finally {
                setLoadingState(false);
            }
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                requestIntent(button.dataset.intentId);
            });
        });
    }

    function initPanels() {
        Array.prototype.slice.call(document.querySelectorAll('.agent-panel[data-agent-panel="patient-chart"]')).forEach(initPanel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPanels);
        return;
    }

    initPanels();
}());
