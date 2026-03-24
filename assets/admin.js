(function () {
    const data = window.FAT_Admin_Data || {};

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function nextIndex(tbody) {
        const current = parseInt(tbody.dataset.nextIndex || '0', 10);
        tbody.dataset.nextIndex = String(current + 1);
        return current;
    }

    function initSchemaBuilder() {
        const inputBody = document.querySelector('#fat-input-schema-table tbody');
        const outputBody = document.querySelector('#fat-output-schema-table tbody');
        const wpMappingBody = document.querySelector('#fat-wp-mapping-table tbody');
        const schemaTargets = {
            input: {
                body: inputBody,
                templateId: 'fat-input-row-template'
            },
            output: {
                body: outputBody,
                templateId: 'fat-output-row-template'
            },
            'wp-mapping': {
                body: wpMappingBody,
                templateId: 'fat-wp-mapping-row-template'
            }
        };

        if (!inputBody && !outputBody && !wpMappingBody) {
            return;
        }

        Object.keys(schemaTargets).forEach(function (target) {
            const body = schemaTargets[target].body;
            if (body) {
                body.dataset.nextIndex = String(body.querySelectorAll('tr').length);
            }
        });

        document.querySelectorAll('.fat-add-row').forEach(function (button) {
            button.addEventListener('click', function () {
                const target = button.getAttribute('data-target');
                const config = schemaTargets[target];
                if (!config) {
                    return;
                }

                const body = config.body;
                const template = document.getElementById(config.templateId);
                if (!body || !template) {
                    return;
                }

                const index = nextIndex(body);
                const html = template.innerHTML.replace(/__INDEX__/g, index);
                body.insertAdjacentHTML('beforeend', html.trim());
            });
        });

        document.addEventListener('click', function (event) {
            const removeButton = event.target.closest('.fat-remove-row');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('tr');
            if (row) {
                row.remove();
            }
        });
    }

    function initRunner() {
        const app = document.getElementById('fat-runner-app');
        const form = document.getElementById('fat-runner-form');
        const select = document.getElementById('fat-tool-select');
        const fieldsWrap = document.getElementById('fat-input-fields');
        const outputsWrap = document.getElementById('fat-output-fields');
        const statusWrap = document.getElementById('fat-runner-status');
        const metaWrap = document.getElementById('fat-tool-meta');
        const submit = document.getElementById('fat-runner-submit');

        if (!app || !form || !select || !fieldsWrap || !outputsWrap || !statusWrap || !metaWrap || !submit) {
            return;
        }

        const tools = Array.isArray(data.tools) ? data.tools : [];
        const byId = {};
        const postCache = {};
        const attachmentCache = { items: null };
        const contentSourceState = {
            source: 'paste',
            selectedPostId: '',
            selectedAttachmentId: '',
            controls: null,
            articleInput: null,
            attachmentControls: null
        };
        const runState = {
            outputs: null,
            applyMeta: null,
            targetType: '',
            targetId: ''
        };

        tools.forEach(function (tool) {
            byId[String(tool.id)] = tool;
        });

        if (!tools.length) {
            return;
        }

        function selectedTool() {
            return byId[String(select.value)] || tools[0];
        }

        function setStatus(message, type) {
            statusWrap.className = 'fat-runner-status';
            if (type) {
                statusWrap.classList.add('is-' + type);
            }
            statusWrap.textContent = message || '';
        }

        function resetRunState() {
            runState.outputs = null;
            runState.applyMeta = null;
            runState.targetType = '';
            runState.targetId = '';
        }

        function renderToolMeta(tool) {
            const description = tool.description ? '<p>' + escapeHtml(tool.description) + '</p>' : '';
            const charLimit = tool.max_input_chars ? '<p class="fat-muted">Max combined input: ' + escapeHtml(tool.max_input_chars) + ' chars</p>' : '';
            metaWrap.innerHTML = '<h2>' + escapeHtml(tool.name) + '</h2>' + description + charLimit;
        }

        function renderField(field) {
            const wrapper = document.createElement('div');
            wrapper.className = 'fat-field';

            const label = document.createElement('label');
            label.setAttribute('for', 'fat-input-' + field.key);
            label.innerHTML = escapeHtml(field.label || field.key) + ' ' + (field.required ? '<span class="fat-required">' + escapeHtml(data.strings.required || 'Required') + '</span>' : '<span class="fat-optional">' + escapeHtml(data.strings.optional || 'Optional') + '</span>');
            wrapper.appendChild(label);

            let input;
            if (field.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 8;
            } else {
                input = document.createElement('input');
                input.type = field.type === 'url' ? 'url' : 'text';
            }

            input.id = 'fat-input-' + field.key;
            input.name = field.key;
            input.className = 'regular-text fat-runner-input';
            if (field.placeholder) {
                input.placeholder = field.placeholder;
            }
            if (field.required) {
                input.required = true;
            }
            if (field.max_length && parseInt(field.max_length, 10) > 0) {
                input.maxLength = parseInt(field.max_length, 10);
            }

            wrapper.appendChild(input);

            if (field.help_text) {
                const help = document.createElement('p');
                help.className = 'description';
                help.textContent = field.help_text;
                wrapper.appendChild(help);
            }

            return wrapper;
        }

        function getToolInputField(tool, key) {
            return (tool.input_schema || []).find(function (field) {
                return field.key === key;
            }) || null;
        }

        function getToolWpConfig(tool) {
            const config = tool && tool.wp_integration ? tool.wp_integration : {};
            const source = config.source || {};
            const apply = config.apply || {};

            return {
                source: {
                    type: source.type || '',
                    allow_manual: !!source.allow_manual,
                    allow_draft: !!source.allow_draft,
                    allow_publish: !!source.allow_publish,
                    allow_attachment: !!source.allow_attachment
                },
                apply: {
                    target: apply.target || '',
                    mappings: Array.isArray(apply.mappings) ? apply.mappings : []
                }
            };
        }

        async function loadPostsForStatus(status) {
            if (postCache[status]) {
                return postCache[status];
            }

            const endpoint = new URL(data.ajaxUrl || '', window.location.origin);
            endpoint.searchParams.set('action', 'fat_runner_posts');
            endpoint.searchParams.set('nonce', data.postsNonce || '');
            endpoint.searchParams.set('status', status);

            const response = await fetch(endpoint.toString(), {
                method: 'GET',
                credentials: 'same-origin'
            });
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                const defaultMessage = data.strings.loadPostsError || 'Unable to load posts for this source.';
                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : defaultMessage);
            }

            const posts = Array.isArray(payload.data.posts) ? payload.data.posts : [];
            postCache[status] = posts;
            return posts;
        }

        async function loadAttachments() {
            if (Array.isArray(attachmentCache.items)) {
                return attachmentCache.items;
            }

            const endpoint = new URL(data.ajaxUrl || '', window.location.origin);
            endpoint.searchParams.set('action', 'fat_runner_attachments');
            endpoint.searchParams.set('nonce', data.postsNonce || '');

            const response = await fetch(endpoint.toString(), {
                method: 'GET',
                credentials: 'same-origin'
            });
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                const defaultMessage = data.strings.loadMediaError || 'Unable to load attachments.';
                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : defaultMessage);
            }

            const attachments = Array.isArray(payload.data.attachments) ? payload.data.attachments : [];
            attachmentCache.items = attachments;
            return attachments;
        }

        function setArticleInputState(disabled) {
            if (!contentSourceState.articleInput) {
                return;
            }
            contentSourceState.articleInput.disabled = !!disabled;
        }

        function buildOptions(selectEl, items, placeholderText, formatLabel) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = placeholderText;
            selectEl.appendChild(placeholder);

            items.forEach(function (item) {
                const option = document.createElement('option');
                option.value = String(item.id || '');
                option.textContent = formatLabel(item);
                selectEl.appendChild(option);
            });
        }

        function renderArticleSourceControls(tool) {
            const articleInput = document.getElementById('fat-input-article_body');
            const wpConfig = getToolWpConfig(tool);
            const legacySourceSupport = !wpConfig.source.type && !!getToolInputField(tool, 'article_body');
            const shouldShow = !!articleInput && (wpConfig.source.type === 'post' || legacySourceSupport);

            if (!shouldShow) {
                contentSourceState.controls = null;
                contentSourceState.articleInput = articleInput || null;
                contentSourceState.source = 'paste';
                contentSourceState.selectedPostId = '';
                return;
            }

            contentSourceState.articleInput = articleInput;
            contentSourceState.source = 'paste';
            contentSourceState.selectedPostId = '';

            const controlsWrap = document.createElement('div');
            controlsWrap.className = 'fat-field fat-content-source-field';

            const sourceLabel = document.createElement('label');
            sourceLabel.setAttribute('for', 'fat-content-source');
            sourceLabel.textContent = data.strings.contentSource || 'Content Source';
            controlsWrap.appendChild(sourceLabel);

            const sourceSelect = document.createElement('select');
            sourceSelect.id = 'fat-content-source';
            sourceSelect.className = 'fat-content-source-select';

            const sourceOptions = [];
            const allowManual = wpConfig.source.type ? wpConfig.source.allow_manual : true;
            const allowDraft = wpConfig.source.type ? wpConfig.source.allow_draft : true;
            const allowPublish = wpConfig.source.type ? wpConfig.source.allow_publish : true;

            if (allowManual) {
                sourceOptions.push({ value: 'paste', label: data.strings.pasteContent || 'Paste Content' });
            }
            if (allowDraft) {
                sourceOptions.push({ value: 'draft', label: data.strings.selectDraft || 'Select Draft' });
            }
            if (allowPublish) {
                sourceOptions.push({ value: 'publish', label: data.strings.selectPublished || 'Select Published Post' });
            }

            sourceOptions.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                sourceSelect.appendChild(option);
            });
            controlsWrap.appendChild(sourceSelect);

            const postSelectWrap = document.createElement('div');
            postSelectWrap.className = 'fat-content-post-wrap';
            postSelectWrap.hidden = true;

            const postSelect = document.createElement('select');
            postSelect.id = 'fat-content-post-select';
            postSelect.className = 'fat-content-post-select';
            postSelectWrap.appendChild(postSelect);

            const postStatus = document.createElement('p');
            postStatus.className = 'description fat-content-post-status';
            postSelectWrap.appendChild(postStatus);

            controlsWrap.appendChild(postSelectWrap);

            const bodyStatus = document.createElement('p');
            bodyStatus.className = 'description fat-content-body-status';
            controlsWrap.appendChild(bodyStatus);

            fieldsWrap.insertBefore(controlsWrap, articleInput.closest('.fat-field'));

            async function updateSourceUi(source) {
                contentSourceState.source = source;
                contentSourceState.selectedPostId = '';
                postSelect.innerHTML = '';
                postStatus.textContent = '';

                if (source === 'paste') {
                    postSelectWrap.hidden = true;
                    setArticleInputState(false);
                    bodyStatus.textContent = '';
                    return;
                }

                postSelectWrap.hidden = false;
                setArticleInputState(true);
                bodyStatus.textContent = data.strings.bodyFilledFromPost || 'Article body will be pulled from the selected post.';
                postStatus.textContent = data.strings.loadingPosts || 'Loading posts…';

                try {
                    const posts = await loadPostsForStatus(source);
                    postSelect.innerHTML = '';
                    buildOptions(postSelect, posts, data.strings.choosePost || 'Choose a post', function (post) {
                        return post.title || ('#' + post.id);
                    });
                    postStatus.textContent = posts.length ? '' : (data.strings.noPostsFound || 'No posts found for this status.');
                } catch (error) {
                    postSelect.innerHTML = '';
                    buildOptions(postSelect, [], data.strings.choosePost || 'Choose a post', function (post) {
                        return post.title || ('#' + post.id);
                    });
                    postStatus.textContent = error.message || (data.strings.loadPostsError || 'Unable to load posts for this source.');
                }
            }

            sourceSelect.addEventListener('change', function () {
                updateSourceUi(sourceSelect.value);
            });
            postSelect.addEventListener('change', function () {
                contentSourceState.selectedPostId = postSelect.value || '';
            });

            contentSourceState.controls = controlsWrap;
            const defaultSource = sourceOptions.length ? sourceOptions[0].value : 'paste';
            sourceSelect.value = defaultSource;
            updateSourceUi(defaultSource);
        }

        function renderAttachmentSourceControls(tool) {
            const wpConfig = getToolWpConfig(tool);
            const shouldShow = wpConfig.source.type === 'attachment' || wpConfig.source.allow_attachment;

            if (!shouldShow) {
                contentSourceState.attachmentControls = null;
                contentSourceState.selectedAttachmentId = '';
                return;
            }

            contentSourceState.selectedAttachmentId = '';

            const controlsWrap = document.createElement('div');
            controlsWrap.className = 'fat-field fat-attachment-source-field';

            const sourceLabel = document.createElement('label');
            sourceLabel.setAttribute('for', 'fat-attachment-source');
            sourceLabel.textContent = data.strings.mediaSource || 'Media Source';
            controlsWrap.appendChild(sourceLabel);

            const attachmentSelect = document.createElement('select');
            attachmentSelect.id = 'fat-attachment-source';
            attachmentSelect.className = 'fat-content-post-select';
            controlsWrap.appendChild(attachmentSelect);

            const attachmentStatus = document.createElement('p');
            attachmentStatus.className = 'description';
            controlsWrap.appendChild(attachmentStatus);

            fieldsWrap.insertBefore(controlsWrap, fieldsWrap.firstChild);

            attachmentSelect.addEventListener('change', function () {
                contentSourceState.selectedAttachmentId = attachmentSelect.value || '';
            });

            async function hydrateAttachments() {
                attachmentStatus.textContent = data.strings.loadingMedia || 'Loading media…';
                attachmentSelect.innerHTML = '';

                try {
                    const attachments = await loadAttachments();
                    buildOptions(attachmentSelect, attachments, data.strings.chooseMedia || 'Choose an attachment', function (attachment) {
                        const title = attachment.title || ('#' + attachment.id);
                        return attachment.filename ? title + ' (' + attachment.filename + ')' : title;
                    });
                    attachmentStatus.textContent = attachments.length ? '' : (data.strings.noMediaFound || 'No attachments found.');
                } catch (error) {
                    buildOptions(attachmentSelect, [], data.strings.chooseMedia || 'Choose an attachment', function (attachment) {
                        return attachment.title || ('#' + attachment.id);
                    });
                    attachmentStatus.textContent = error.message || (data.strings.loadMediaError || 'Unable to load attachments.');
                }
            }

            contentSourceState.attachmentControls = controlsWrap;
            hydrateAttachments();
        }

        function renderRunnerActions() {
            let actionRow = form.querySelector('.fat-runner-actions');
            if (!actionRow) {
                actionRow = document.createElement('p');
                actionRow.className = 'fat-runner-actions';
                form.appendChild(actionRow);
            }

            actionRow.innerHTML = '';

            submit.textContent = data.strings.generate || 'Generate';
            submit.className = 'button button-primary';
            submit.type = 'submit';
            actionRow.appendChild(submit);

            const generateApplyButton = document.createElement('button');
            generateApplyButton.type = 'button';
            generateApplyButton.id = 'fat-runner-generate-apply';
            generateApplyButton.className = 'button';
            generateApplyButton.textContent = data.strings.generateApply || 'Generate + Apply';
            generateApplyButton.hidden = true;
            generateApplyButton.addEventListener('click', function () {
                runTool({ preventDefault: function () {} }, { applyAfterGenerate: true });
            });
            actionRow.appendChild(generateApplyButton);
        }

        function renderInputs(tool) {
            fieldsWrap.innerHTML = '';
            outputsWrap.innerHTML = '';
            setStatus('');
            resetRunState();
            renderToolMeta(tool);

            (tool.input_schema || []).forEach(function (field) {
                fieldsWrap.appendChild(renderField(field));
            });

            renderArticleSourceControls(tool);
            renderAttachmentSourceControls(tool);
            renderRunnerActions();
        }

        function copyText(value, button) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function () {
                    const original = button.textContent;
                    button.textContent = data.strings.copied || 'Copied';
                    window.setTimeout(function () {
                        button.textContent = original;
                    }, 1500);
                });
                return;
            }

            const helper = document.createElement('textarea');
            helper.value = value;
            document.body.appendChild(helper);
            helper.select();
            document.execCommand('copy');
            document.body.removeChild(helper);
        }

        function availableApplyMappings(applyMeta) {
            const mappings = (applyMeta && Array.isArray(applyMeta.mappings)) ? applyMeta.mappings : [];
            const outputKeys = (applyMeta && Array.isArray(applyMeta.output_keys)) ? applyMeta.output_keys : [];

            return mappings.filter(function (mapping) {
                return mapping && mapping.output_key && outputKeys.indexOf(mapping.output_key) !== -1;
            });
        }

        function selectedApplyFields(panel) {
            return Array.from(panel.querySelectorAll('input[type="checkbox"]:checked')).map(function (input) {
                return input.value;
            });
        }

        async function applyOutputs(context) {
            const applyMeta = context && context.applyMeta ? context.applyMeta : runState.applyMeta;
            const outputs = context && context.outputs ? context.outputs : runState.outputs;
            const panel = context && context.panel ? context.panel : outputsWrap.querySelector('.fat-apply-panel');

            if (!applyMeta || !outputs || !panel) {
                setStatus(data.strings.applyUnavailable || 'No apply mappings are available for the generated outputs.', 'error');
                return;
            }

            const applyFields = selectedApplyFields(panel);
            if (!applyFields.length) {
                setStatus(data.strings.applyNoFields || 'Select at least one field to apply.', 'error');
                return;
            }

            let targetId = runState.targetId || String(applyMeta.target_id || '');
            if (applyMeta.target_type === 'attachment') {
                targetId = targetId || contentSourceState.selectedAttachmentId;
            }

            if (!targetId) {
                setStatus(data.strings.applyTargetRequired || 'Please choose a target before applying.', 'error');
                return;
            }

            const applyButton = panel.querySelector('.fat-apply-button');
            const generateApplyButton = document.getElementById('fat-runner-generate-apply');
            submit.disabled = true;
            if (applyButton) {
                applyButton.disabled = true;
            }
            if (generateApplyButton) {
                generateApplyButton.disabled = true;
            }
            setStatus(data.strings.running || 'Running…', 'loading');

            try {
                const response = await fetch(data.applyUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': data.nonce
                    },
                    body: JSON.stringify({
                        tool_id: selectedTool().id,
                        target_type: applyMeta.target_type,
                        target_id: parseInt(targetId, 10),
                        outputs: outputs,
                        apply_fields: applyFields
                    })
                });

                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    const message = (payload && payload.message) ? payload.message : (data.strings.applyError || 'Unable to apply selected outputs.');
                    throw new Error(message);
                }

                setStatus(data.strings.applySuccess || 'Selected fields were applied successfully.', 'success');
            } catch (error) {
                setStatus(error.message || data.strings.applyError || 'Unable to apply selected outputs.', 'error');
            } finally {
                submit.disabled = false;
                if (applyButton) {
                    applyButton.disabled = false;
                }
                if (generateApplyButton) {
                    generateApplyButton.disabled = false;
                }
            }
        }

        function renderApplyPanel(outputs, applyMeta) {
            const mappings = availableApplyMappings(applyMeta);
            if (!applyMeta || !applyMeta.enabled || !mappings.length) {
                return null;
            }

            const panel = document.createElement('div');
            panel.className = 'fat-apply-panel fat-output-card';

            const title = document.createElement('h3');
            title.textContent = data.strings.applyPanelTitle || 'Apply to WordPress';
            panel.appendChild(title);

            const help = document.createElement('p');
            help.className = 'fat-muted';
            help.textContent = data.strings.applyFields || 'Fields to apply';
            panel.appendChild(help);

            let selectedTargetId = String(applyMeta.target_id || '');
            if (applyMeta.target_type === 'post') {
                const targetWrap = document.createElement('div');
                targetWrap.className = 'fat-field';

                const label = document.createElement('label');
                label.textContent = data.strings.applyTarget || 'Target';
                targetWrap.appendChild(label);

                const targetSelect = document.createElement('select');
                targetSelect.className = 'fat-content-post-select';
                targetWrap.appendChild(targetSelect);
                panel.appendChild(targetWrap);

                buildOptions(targetSelect, [], data.strings.choosePost || 'Choose a post', function (post) {
                    return post.title || ('#' + post.id);
                });

                Promise.all([loadPostsForStatus('draft'), loadPostsForStatus('publish')]).then(function (result) {
                    const draftPosts = result[0] || [];
                    const publishPosts = result[1] || [];
                    const merged = draftPosts.concat(
                        publishPosts.filter(function (post) {
                            return !draftPosts.some(function (draftPost) {
                                return String(draftPost.id) === String(post.id);
                            });
                        })
                    );
                    targetSelect.innerHTML = '';
                    buildOptions(targetSelect, merged, data.strings.choosePost || 'Choose a post', function (post) {
                        return post.title || ('#' + post.id);
                    });
                    if (selectedTargetId) {
                        targetSelect.value = selectedTargetId;
                    }
                }).catch(function () {
                    // Keep fallback placeholder only.
                });

                targetSelect.addEventListener('change', function () {
                    selectedTargetId = targetSelect.value || '';
                    runState.targetId = selectedTargetId;
                });
            } else if (applyMeta.target_type === 'attachment') {
                selectedTargetId = selectedTargetId || contentSourceState.selectedAttachmentId;
            }

            const list = document.createElement('div');
            list.className = 'fat-apply-list';
            mappings.forEach(function (mapping, index) {
                const row = document.createElement('label');
                row.className = 'fat-inline-check';

                const input = document.createElement('input');
                input.type = 'checkbox';
                input.value = mapping.field || mapping.apply_key || '';
                input.checked = index === 0;

                row.appendChild(input);
                row.appendChild(document.createTextNode(' ' + (mapping.label || mapping.output_key)));
                list.appendChild(row);
            });
            panel.appendChild(list);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary fat-apply-button';
            button.textContent = data.strings.applySelected || 'Apply Selected Fields';
            button.addEventListener('click', function () {
                applyOutputs({
                    applyMeta: applyMeta,
                    outputs: outputs,
                    panel: panel
                });
            });
            panel.appendChild(button);

            runState.targetType = applyMeta.target_type || '';
            runState.targetId = selectedTargetId || '';

            return panel;
        }

        function renderOutputs(tool, outputs, meta) {
            outputsWrap.innerHTML = '';

            const metaLine = document.createElement('div');
            metaLine.className = 'fat-output-meta';
            metaLine.textContent = 'Model: ' + (meta.model || '') + ' | Latency: ' + (meta.latency_ms || 0) + ' ms';
            outputsWrap.appendChild(metaLine);

            (tool.output_schema || []).forEach(function (field) {
                const value = outputs[field.key] || '';
                const card = document.createElement('div');
                card.className = 'fat-output-card';

                const titleRow = document.createElement('div');
                titleRow.className = 'fat-output-header';

                const title = document.createElement('h3');
                title.textContent = field.label || field.key;
                titleRow.appendChild(title);

                if (field.copyable) {
                    const copyButton = document.createElement('button');
                    copyButton.type = 'button';
                    copyButton.className = 'button button-secondary';
                    copyButton.textContent = data.strings.copy || 'Copy';
                    copyButton.addEventListener('click', function () {
                        copyText(value, copyButton);
                    });
                    titleRow.appendChild(copyButton);
                }

                card.appendChild(titleRow);

                const output = document.createElement('textarea');
                output.readOnly = true;
                output.rows = field.type === 'long_text' ? 8 : 4;
                output.value = value;
                card.appendChild(output);

                outputsWrap.appendChild(card);
            });

            const applyPanel = renderApplyPanel(outputs, (meta && meta.apply) ? meta.apply : null);
            if (applyPanel) {
                outputsWrap.appendChild(applyPanel);
                const generateApplyButton = document.getElementById('fat-runner-generate-apply');
                if (generateApplyButton) {
                    generateApplyButton.hidden = false;
                }
            }
        }

        async function runTool(event, options) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            const config = options || {};
            const tool = selectedTool();
            const formData = new FormData(form);
            const inputs = {};

            (tool.input_schema || []).forEach(function (field) {
                inputs[field.key] = formData.get(field.key) || '';
            });

            if (contentSourceState.controls) {
                inputs.__fat_article_source = contentSourceState.source;
                inputs.__fat_article_post_id = contentSourceState.selectedPostId || '';

                if ((contentSourceState.source === 'draft' || contentSourceState.source === 'publish') && !contentSourceState.selectedPostId) {
                    setStatus(data.strings.postSelectionRequired || 'Please select a post for the chosen content source.', 'error');
                    return;
                }
            }

            if (contentSourceState.attachmentControls) {
                inputs.__fat_attachment_id = contentSourceState.selectedAttachmentId || '';
                if (!inputs.__fat_attachment_id) {
                    setStatus(data.strings.mediaSelectionRequired || 'Please select an attachment.', 'error');
                    return;
                }
            }

            const generateApplyButton = document.getElementById('fat-runner-generate-apply');
            submit.disabled = true;
            if (generateApplyButton) {
                generateApplyButton.disabled = true;
            }
            setStatus(data.strings.running || 'Running…', 'loading');
            outputsWrap.innerHTML = '';
            resetRunState();

            try {
                const response = await fetch(data.restUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': data.nonce
                    },
                    body: JSON.stringify({
                        tool_id: tool.id,
                        inputs: inputs
                    })
                });

                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    const message = (payload && payload.message) ? payload.message : (data.strings.runError || 'The tool could not be run.');
                    throw new Error(message);
                }

                renderOutputs(tool, payload.data.outputs || {}, payload.data.meta || {});
                runState.outputs = payload.data.outputs || {};
                runState.applyMeta = payload.data.meta && payload.data.meta.apply ? payload.data.meta.apply : null;
                setStatus('Completed.', 'success');

                if (config.applyAfterGenerate && runState.applyMeta && runState.applyMeta.enabled) {
                    const panel = outputsWrap.querySelector('.fat-apply-panel');
                    if (panel) {
                        await applyOutputs({
                            applyMeta: runState.applyMeta,
                            outputs: runState.outputs,
                            panel: panel
                        });
                    }
                }
            } catch (error) {
                setStatus(error.message || data.strings.runError || 'The tool could not be run.', 'error');
            } finally {
                submit.disabled = false;
                if (generateApplyButton) {
                    generateApplyButton.disabled = false;
                }
            }
        }

        select.addEventListener('change', function () {
            renderInputs(selectedTool());
        });
        form.addEventListener('submit', runTool);

        renderInputs(selectedTool());
    }

    ready(function () {
        initSchemaBuilder();
        initRunner();
    });
})();
