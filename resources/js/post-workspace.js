(() => {
    'use strict';

    const create = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    };

    const dateLabel = (value, fallback = 'Not available') => {
        if (!value) return fallback;
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
    };

    const relativeTime = (value) => {
        if (!value) return 'never';
        const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
        if (!Number.isFinite(seconds)) return 'unknown';
        if (seconds < 10) return 'just now';
        if (seconds < 60) return seconds + ' seconds ago';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + ' minute' + (minutes === 1 ? '' : 's') + ' ago';
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + ' hour' + (hours === 1 ? '' : 's') + ' ago';
        const days = Math.floor(hours / 24);
        return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    };

    const appendFact = (list, label, value, link) => {
        const row = create('div');
        row.append(create('dt', '', label));
        const detail = create('dd');
        if (link) {
            const anchor = create('a', '', value || link);
            anchor.href = link;
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            detail.append(anchor);
        } else {
            detail.textContent = value || 'Not available';
        }
        row.append(detail);
        list.append(row);
    };

    const taxonomyValue = (post) => {
        const groups = Object.values(post.taxonomies || {}).filter((taxonomy) => {
            return Array.isArray(taxonomy?.terms) && taxonomy.terms.length;
        });
        if (!groups.length) return 'No terms assigned';
        return groups.map((taxonomy) => {
            return (taxonomy.label || 'Terms') + ': ' + taxonomy.terms.map((term) => term.name).join(', ');
        }).join(' | ');
    };

    const previewDocument = (post) => {
        const content = post.content_html || '<p>No post content has been added yet.</p>';
        return '<!doctype html><html><head><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'; font-src https: data:">'
            + '<base target="_blank"><style>body{margin:0;padding:28px;color:#20272d;background:#fff;font:16px/1.72 Georgia,serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}a{color:#176b87}h1,h2,h3,h4{color:#12191f;line-height:1.2}blockquote{margin-left:0;border-left:4px solid #bfd0d8;padding-left:18px;color:#53616c}pre{white-space:pre-wrap}</style></head><body>'
            + content + '</body></html>';
    };

    const renderPreview = (post) => {
        const panel = create('article', 'hwp-workspace__panel');
        const heading = create('header', 'hwp-workspace__panel-head');
        const headingCopy = create('div');
        headingCopy.append(create('small', '', 'Remote post preview'));
        headingCopy.append(create('h4', '', post.status === 'publish' ? 'Published content' : 'Current draft content'));
        heading.append(headingCopy, create('span', '', (post.word_count || 0) + ' words'));
        panel.append(heading);

        if (post.featured_image?.url) {
            const image = create('img', 'hwp-workspace__preview-cover');
            image.src = post.featured_image.url;
            image.alt = post.featured_image.alt || '';
            image.loading = 'lazy';
            panel.append(image);
        }

        const copy = create('div', 'hwp-workspace__preview-copy');
        copy.append(create('h3', '', post.title || 'Untitled WordPress post'));
        const excerpt = create('p');
        excerpt.textContent = String(post.excerpt_raw || '').trim()
            || String(post.content_raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240)
            || 'No excerpt or content is available yet.';
        copy.append(excerpt);
        panel.append(copy);

        const frame = create('iframe', 'hwp-workspace__preview-frame');
        frame.title = 'WordPress post content preview';
        frame.setAttribute('sandbox', 'allow-same-origin');
        frame.srcdoc = previewDocument(post);
        frame.addEventListener('load', () => {
            try {
                frame.style.height = Math.max(420, Math.min(1100, frame.contentDocument.body.scrollHeight + 56)) + 'px';
            } catch (error) {
                frame.style.height = '520px';
            }
        });
        panel.append(frame);
        return panel;
    };

    const renderDetails = (workspace) => {
        const post = workspace.post;
        const sidebar = create('aside', 'hwp-workspace__sidebar');
        const details = create('section', 'hwp-workspace__panel');
        const detailsHead = create('header', 'hwp-workspace__panel-head');
        const detailsCopy = create('div');
        detailsCopy.append(create('small', '', 'Post information'));
        detailsCopy.append(create('h4', '', 'Current WordPress record'));
        detailsHead.append(detailsCopy);
        details.append(detailsHead);
        const facts = create('dl', 'hwp-workspace__facts');
        appendFact(facts, 'Post ID', '#' + post.id);
        appendFact(facts, 'Status', post.status_label || post.status);
        appendFact(facts, 'Post type', post.type);
        appendFact(facts, 'Author', [post.author?.name, post.author?.username ? '@' + post.author.username : ''].filter(Boolean).join(' | '));
        appendFact(facts, 'Created', dateLabel(post.date));
        appendFact(facts, 'Modified', dateLabel(post.modified));
        appendFact(facts, 'Slug', post.slug || 'Not assigned');
        appendFact(facts, 'Comments', String(post.comment_count ?? 0) + ' | ' + (post.comment_status || 'unknown'));
        appendFact(facts, 'Taxonomies', taxonomyValue(post));
        appendFact(facts, 'Public URL', post.permalink || 'Not available', post.permalink);
        details.append(facts);
        sidebar.append(details);

        const activity = create('section', 'hwp-workspace__panel');
        const activityHead = create('header', 'hwp-workspace__panel-head');
        const activityCopy = create('div');
        activityCopy.append(create('small', '', 'Account activity'));
        activityCopy.append(create('h4', '', 'Last login information'));
        activityHead.append(activityCopy);
        activity.append(activityHead);
        const activityFacts = create('dl', 'hwp-workspace__facts');
        appendFact(activityFacts, 'Customer portal', dateLabel(workspace.activity?.portal_last_login_at, 'Never recorded'));
        appendFact(activityFacts, 'WordPress author', dateLabel(post.author?.last_login_at, 'Never recorded'));
        appendFact(activityFacts, 'Customer workspace', dateLabel(workspace.activity?.customer_workspace_login_at, 'Never generated'));
        appendFact(activityFacts, 'Admin workspace', dateLabel(workspace.activity?.admin_workspace_login_at, 'Never generated'));
        activity.append(activityFacts);
        sidebar.append(activity);

        const deliverables = Object.values(workspace.extensions || {}).flatMap((extension) => {
            return Array.isArray(extension?.deliverables) ? extension.deliverables : [];
        });
        const deliverablePanel = create('section', 'hwp-workspace__panel');
        const deliverableHead = create('header', 'hwp-workspace__panel-head');
        const deliverableCopy = create('div');
        deliverableCopy.append(create('small', '', 'Distribution output'));
        deliverableCopy.append(create('h4', '', 'Syndicated deliverables'));
        deliverableHead.append(deliverableCopy, create('span', '', deliverables.length + ' link' + (deliverables.length === 1 ? '' : 's')));
        deliverablePanel.append(deliverableHead);
        if (deliverables.length) {
            const list = create('div', 'hwp-workspace__deliverables');
            deliverables.forEach((deliverable) => {
                const link = create('a', 'hwp-workspace__deliverable');
                link.href = deliverable.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                const label = create('span');
                label.append(create('strong', '', deliverable.label || 'Deliverable'));
                label.append(create('small', '', deliverable.url));
                link.append(label, create('span', '', 'Open'));
                list.append(link);
            });
            deliverablePanel.append(list);
        } else {
            deliverablePanel.append(create('p', 'hwp-workspace__empty', 'No syndicated deliverable links are available yet. Refresh after the post is distributed.'));
        }
        Object.entries(workspace.extension_errors || {}).forEach(([key, message]) => {
            deliverablePanel.append(create('p', 'hwp-workspace__notice', key + ': ' + message));
        });
        sidebar.append(deliverablePanel);
        return sidebar;
    };

    const initialize = (root) => {
        const refresh = root.querySelector('[data-wordpress-post-refresh]');
        const refreshLabel = root.querySelector('[data-wordpress-post-refresh-label]');
        const activity = root.querySelector('[data-wordpress-post-activity]');
        const content = root.querySelector('[data-wordpress-post-content]');
        const status = root.querySelector('[data-wordpress-post-status]');
        const updated = root.querySelector('[data-wordpress-post-updated]');
        const postLink = root.querySelector('[data-wordpress-post-link]');
        const initialPayload = root.querySelector('[data-wordpress-initial-workspace]');
        let cacheRebuiltAt = null;

        const updateRelativeTime = () => {
            updated.textContent = cacheRebuiltAt
                ? 'Cache rebuilt ' + relativeTime(cacheRebuiltAt)
                : 'Cache has not been built';
        };

        const renderWorkspace = (workspace) => {
            if (!workspace?.post) return false;
            const post = workspace.post;
            status.textContent = post.status_label || post.status || 'Unknown';
            status.className = 'hwp-workspace__status hwp-workspace__status--'
                + (post.status === 'publish' ? 'published' : (post.status || 'pending'));
            if (post.permalink) {
                postLink.href = post.permalink;
                postLink.classList.remove('hidden');
            }
            content.replaceChildren();
            const grid = create('div', 'hwp-workspace__grid');
            grid.append(renderPreview(post), renderDetails(workspace));
            content.append(grid);
            cacheRebuiltAt = workspace.cache_rebuilt_at || workspace.refreshed_at || null;
            updateRelativeTime();
            return true;
        };

        let initialWorkspace = null;
        try {
            initialWorkspace = JSON.parse(initialPayload?.textContent || 'null');
        } catch (error) {
            initialWorkspace = null;
        }
        if (renderWorkspace(initialWorkspace)) {
            activity.textContent = 'Showing the latest saved snapshot. WordPress is contacted only when Refresh post is pressed.';
        }

        const load = async () => {
            if (root.dataset.loading === '1') return;
            root.dataset.loading = '1';
            refresh.disabled = true;
            refresh.classList.add('is-loading');
            refreshLabel.textContent = 'Refreshing...';
            activity.className = 'hwp-workspace__activity';
            activity.textContent = 'Fetching the post, metadata, status, logins, and deliverable links from WordPress...';

            try {
                const response = await fetch(root.dataset.refreshUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': root.dataset.csrfToken || '',
                    },
                });
                const payload = await response.json();
                if (!response.ok || payload.success === false || !payload.workspace?.post) {
                    throw new Error(payload.message || 'The WordPress workspace could not be refreshed.');
                }
                const workspace = payload.workspace;
                renderWorkspace(workspace);
                if (workspace.refresh_succeeded === false) {
                    activity.className = 'hwp-workspace__activity is-error';
                    activity.textContent = workspace.refresh_error
                        ? 'Refresh failed; the previous cache remains visible. ' + workspace.refresh_error
                        : 'Refresh failed; the previous cache remains visible.';
                } else {
                    activity.textContent = payload.message || 'WordPress cache rebuilt successfully.';
                }
            } catch (error) {
                activity.className = 'hwp-workspace__activity is-error';
                activity.textContent = error.message || 'The WordPress workspace could not be refreshed.';
                status.textContent = 'Refresh failed';
                status.className = 'hwp-workspace__status hwp-workspace__status--failed';
                if (!content.children.length) {
                    content.replaceChildren(create('p', 'hwp-workspace__empty', 'The post preview is unavailable. The order and remote post were not changed.'));
                }
            } finally {
                root.dataset.loading = '0';
                refresh.disabled = false;
                refresh.classList.remove('is-loading');
                refreshLabel.textContent = 'Refresh post';
            }
        };

        refresh.addEventListener('click', load);
        root.querySelectorAll('[data-wordpress-login-form]').forEach((form) => {
            let submitting = false;
            form.addEventListener('submit', (event) => {
                const button = form.querySelector('button');
                if (submitting) {
                    event.preventDefault();
                    return;
                }
                submitting = true;
                const targetName = 'hwp-login-' + Date.now() + '-'
                    + Math.random().toString(36).slice(2);
                const popup = window.open('about:blank', targetName);
                if (popup) {
                    event.preventDefault();
                    form.target = targetName;
                    form.submit();
                    popup.opener = null;
                }
                window.setTimeout(() => {
                    button.classList.add('is-loading');
                    button.setAttribute('aria-disabled', 'true');
                    button.setAttribute('aria-busy', 'true');
                }, popup ? 0 : 1000);
                window.setTimeout(() => {
                    submitting = false;
                    button.classList.remove('is-loading');
                    button.removeAttribute('aria-disabled');
                    button.removeAttribute('aria-busy');
                }, 5000);
            });
        });
        window.setInterval(updateRelativeTime, 30000);
    };

    const boot = () => document.querySelectorAll('[data-wordpress-post-workspace]').forEach(initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
