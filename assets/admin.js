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

        if (!inputBody && !outputBody) {
            return;
        }

        if (inputBody) {
            inputBody.dataset.nextIndex = String(inputBody.querySelectorAll('tr').length);
        }
        if (outputBody) {
            outputBody.dataset.nextIndex = String(outputBody.querySelectorAll('tr').length);
        }

        document.querySelectorAll('.fat-add-row').forEach(function (button) {
            button.addEventListener('click', function () {
                const target = button.getAttribute('data-target');
                const body = target === 'input' ? inputBody : outputBody;
                const template = document.getElementById(target === 'input' ? 'fat-input-row-template' : 'fat-output-row-template');
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
        const contentSourceState = {
            source: 'paste',
            selectedPostId: '',
            controls: null,
            articleInput: null
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

        function setArticleInputState(disabled) {
            if (!contentSourceState.articleInput) {
                return;
            }
            contentSourceState.articleInput.disabled = !!disabled;
        }

        function buildPostOptions(selectEl, posts) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = data.strings.choosePost || 'Choose a post';
            selectEl.appendChild(placeholder);

            posts.forEach(function (post) {
                const option = document.createElement('option');
                option.value = String(post.id || '');
                option.textContent = post.title || ('#' + post.id);
                selectEl.appendChild(option);
            });
        }

        function renderArticleSourceControls(tool) {
            const articleField = getToolInputField(tool, 'article_body');
            if (!articleField) {
                contentSourceState.controls = null;
                contentSourceState.articleInput = null;
                contentSourceState.source = 'paste';
                contentSourceState.selectedPostId = '';
                return;
            }

            const articleInput = document.getElementById('fat-input-article_body');
            if (!articleInput) {
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
            [
                { value: 'paste', label: data.strings.pasteContent || 'Paste Content' },
                { value: 'draft', label: data.strings.selectDraft || 'Select Draft' },
                { value: 'publish', label: data.strings.selectPublished || 'Select Published Post' }
            ].forEach(function (item) {
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
                    buildPostOptions(postSelect, posts);
                    postStatus.textContent = posts.length ? '' : (data.strings.noPostsFound || 'No posts found for this status.');
                } catch (error) {
                    postSelect.innerHTML = '';
                    buildPostOptions(postSelect, []);
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
            updateSourceUi('paste');
        }

        function renderInputs(tool) {
            fieldsWrap.innerHTML = '';
            outputsWrap.innerHTML = '';
            setStatus('');
            renderToolMeta(tool);

            (tool.input_schema || []).forEach(function (field) {
                fieldsWrap.appendChild(renderField(field));
            });
            renderArticleSourceControls(tool);
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
        }

        async function runTool(event) {
            event.preventDefault();
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

            submit.disabled = true;
            setStatus(data.strings.running || 'Running…', 'loading');
            outputsWrap.innerHTML = '';

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

                renderOutputs(tool, payload.data.outputs, payload.data.meta || {});
                setStatus('Completed.', 'success');
            } catch (error) {
                setStatus(error.message || data.strings.runError || 'The tool could not be run.', 'error');
            } finally {
                submit.disabled = false;
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
