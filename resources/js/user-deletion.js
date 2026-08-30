(function (window) {
    "use strict";

    const STATE_KEY = "wordpress_user_deletion";
    let searchSequence = 0;

    const positiveIds = (values) => {
        if (typeof values === "string") values = values.split(/[\s,]+/);
        if (!Array.isArray(values)) values = [values];

        return [...new Set(values
            .map((value) => Number.parseInt(value, 10))
            .filter((value) => Number.isFinite(value) && value > 0))];
    };

    const candidateId = (candidate) => Number.parseInt(
        candidate && (candidate.wp_user_id ?? candidate.id ?? candidate.ID ?? candidate.value),
        10,
    );

    const destinationLabel = (candidate) => {
        const id = candidateId(candidate);
        const name = String(candidate && (candidate.name || candidate.display_name || candidate.user_login) || "").trim();
        return `${name || `WordPress user #${id}`} · WP #${id}`;
    };

    const cachedContentState = (host) => {
        const rawCount = host && (host.content_count ?? host.delete_content_count);
        const contentCount = Number.parseInt(rawCount, 10);
        const contentCountKnown = Number.isFinite(contentCount)
            && !!(host && (
                host.content_count_known === true || host.delete_content_count_known === true
            ));

        return {
            contentCount: contentCountKnown ? Math.max(0, contentCount) : null,
            contentCountKnown,
        };
    };

    const ensureState = (host) => {
        if (!host || typeof host !== "object") return {};
        const cached = cachedContentState(host);
        if (!host[STATE_KEY] || typeof host[STATE_KEY] !== "object") {
            host[STATE_KEY] = {
                contextLoaded: host.delete_context_loaded === true || cached.contentCountKnown,
                contentCount: cached.contentCountKnown
                    ? cached.contentCount
                    : (host.content_count ?? host.delete_content_count ?? null),
                contentCountKnown: cached.contentCountKnown || host.delete_content_count_known === true,
                requiresReassignment: cached.contentCountKnown
                    ? cached.contentCount > 0
                    : (host.delete_requires_reassignment ?? host.delete_requires_reassign ?? true),
                candidateContextLoaded: host.delete_candidate_context_loaded === true,
                candidateGroups: Array.isArray(host.delete_candidate_groups) ? host.delete_candidate_groups : [],
                destination: host.delete_reassign_item || null,
                contentAction: host.delete_content_action === "delete" ? "delete" : "reassign",
            };
        }
        const state = host[STATE_KEY];
        if (state.contextLoaded !== true && cached.contentCountKnown) {
            state.contextLoaded = true;
            state.contentCount = cached.contentCount;
            state.contentCountKnown = true;
            state.requiresReassignment = cached.contentCount > 0;
        }
        return state;
    };

    const syncCompatibilityState = (host) => {
        if (!host || typeof host !== "object") return {};
        const state = ensureState(host);
        host.delete_context_loaded = state.contextLoaded === true;
        host.delete_content_count = state.contentCount ?? null;
        host.delete_post_count = state.contentCount ?? null;
        host.delete_content_count_known = state.contentCountKnown === true;
        host.delete_requires_reassignment = state.requiresReassignment !== false;
        host.delete_requires_reassign = state.requiresReassignment !== false;
        host.delete_candidate_context_loaded = state.candidateContextLoaded === true;
        host.delete_candidate_groups = Array.isArray(state.candidateGroups) ? state.candidateGroups : [];
        host.delete_content_action = state.contentAction === "delete" ? "delete" : "reassign";
        host.delete_reassign_item = state.destination || null;
        host.delete_reassign_user_id = state.destination ? String(candidateId(state.destination) || "") : "";
        host.delete_reassign_user_label = state.destination ? destinationLabel(state.destination) : "";
        host.delete_reassign_user_publication_id = state.destination && state.destination.publication_id
            ? String(state.destination.publication_id)
            : null;
        return state;
    };

    const normalizeCandidateGroups = (context) => {
        if (Array.isArray(context && context.candidate_groups)) {
            return context.candidate_groups
                .filter((group) => group && Array.isArray(group.items) && group.items.length)
                .map((group) => ({
                    key: String(group.key || "candidates"),
                    label: String(group.label || "Suggested users"),
                    items: group.items,
                }));
        }

        const suggestions = context && context.suggestions && typeof context.suggestions === "object"
            ? context.suggestions
            : {};
        const groups = [];
        if (Array.isArray(suggestions.admins) && suggestions.admins.length) {
            groups.push({ key: "administrators", label: "Administrators", items: suggestions.admins });
        }
        if (Array.isArray(suggestions.top_posters) && suggestions.top_posters.length) {
            groups.push({ key: "top_authors", label: "Authors with the most content", items: suggestions.top_posters });
        }
        return groups;
    };

    const dispatchChange = (root, host, selected) => {
        if (!root || typeof root.dispatchEvent !== "function") return;
        root.dispatchEvent(new CustomEvent("wordpress-user-reassignment-changed", {
            bubbles: true,
            detail: {
                source: host,
                selected: selected || null,
            },
        }));
    };

    const searchData = (root) => {
        const shell = root && root.querySelector ? root.querySelector("[data-wordpress-user-reassignment-search]") : null;
        const element = shell && shell.querySelector ? shell.querySelector("[x-data]") : null;
        if (!element || !window.Alpine || typeof window.Alpine.$data !== "function") return null;
        return { element, component: window.Alpine.$data(element) };
    };

    const api = {
        initialize(host) {
            syncCompatibilityState(host);
            return host;
        },

        applyContext(host, context) {
            const state = ensureState(host);
            const rawCount = context && (context.content_count ?? context.post_count);
            const count = Number.parseInt(rawCount, 10);
            state.contextLoaded = true;
            state.contentCount = Number.isFinite(count) ? count : null;
            state.contentCountKnown = context && context.content_count_known !== undefined
                ? context.content_count_known === true
                : Number.isFinite(count);
            state.requiresReassignment = context && context.requires_reassignment !== undefined
                ? context.requires_reassignment !== false
                : !(context && context.requires_reassign === false);
            state.candidateContextLoaded = true;
            state.candidateGroups = normalizeCandidateGroups(context || {});
            if (state.requiresReassignment === false) state.contentAction = "reassign";
            syncCompatibilityState(host);
            host.delete_suggestions = context && context.suggestions && typeof context.suggestions === "object"
                ? context.suggestions
                : { admins: [], top_posters: [] };
            return state;
        },

        state(host) {
            return ensureState(host);
        },

        needsReassignment(host) {
            const state = ensureState(host);
            return !(state.contextLoaded === true && state.requiresReassignment === false);
        },

        needsCandidateContext(host) {
            const state = ensureState(host);
            return state.requiresReassignment !== false && state.candidateContextLoaded !== true;
        },

        contentCountLabel(host) {
            const state = ensureState(host);
            const count = Number.parseInt(state.contentCount, 10);
            if (!Number.isFinite(count)) return "Content count unknown";
            return `${count} content item${count === 1 ? "" : "s"}`;
        },

        contentAction(host) {
            return ensureState(host).contentAction === "delete" ? "delete" : "reassign";
        },

        deletesContent(host) {
            const state = ensureState(host);
            return state.requiresReassignment !== false && state.contentAction === "delete";
        },

        setContentAction(host, action, root = null) {
            const state = ensureState(host);
            state.contentAction = action === "delete" ? "delete" : "reassign";
            if (state.contentAction === "delete") {
                state.destination = null;
                host.delete_error = false;
                host.delete_message = "Ready. This user and all content they own will be permanently deleted.";
            } else {
                host.delete_error = false;
                host.delete_message = state.destination
                    ? `Ready. All existing content will be assigned to ${destinationLabel(state.destination)}.`
                    : "Choose who receives all existing content.";
            }
            syncCompatibilityState(host);
            dispatchChange(root, host, state.destination);
            return state.contentAction;
        },


        candidateGroups(host) {
            return normalizeCandidateGroups({ candidate_groups: ensureState(host).candidateGroups });
        },

        candidateMeta(candidate) {
            if (!candidate) return "";
            const parts = [];
            const count = Number.parseInt(candidate.post_count, 10);
            if (Number.isFinite(count)) parts.push(`${count} content item${count === 1 ? "" : "s"}`);
            if (Array.isArray(candidate.roles) && candidate.roles.length) parts.push(candidate.roles.join(", "));
            if (candidate.user_login) parts.push(`@${candidate.user_login}`);
            const id = candidateId(candidate);
            if (Number.isFinite(id) && id > 0) parts.push(`WP #${id}`);
            return parts.join(" · ");
        },

        selectedId(host) {
            return candidateId(ensureState(host).destination) || null;
        },

        selectedLabel(host) {
            const destination = ensureState(host).destination;
            return destination ? destinationLabel(destination) : "";
        },

        select(host, candidate, options = {}, root = null) {
            const id = candidateId(candidate);
            const publicationId = Number.parseInt(options.publicationId, 10);
            const candidatePublicationId = Number.parseInt(candidate && candidate.publication_id, 10);
            const excluded = new Set(positiveIds(options.excludedUserIds || []).map(String));

            if (!Number.isFinite(id) || id <= 0) {
                host.delete_error = true;
                host.delete_message = "Choose a valid WordPress user from the search results.";
                return false;
            }
            if (Number.isFinite(publicationId) && publicationId > 0 && Number.isFinite(candidatePublicationId) && candidatePublicationId > 0 && publicationId !== candidatePublicationId) {
                host.delete_error = true;
                host.delete_message = `Choose a user from ${options.publicationLabel || "this WordPress site"}.`;
                return false;
            }
            if (excluded.has(String(id))) {
                host.delete_error = true;
                host.delete_message = "Choose a user who is not selected for deletion.";
                return false;
            }

            const name = String(candidate && (candidate.name || candidate.display_name || candidate.user_login) || "").trim();
            const destination = {
                ...(candidate || {}),
                id,
                ID: id,
                wp_user_id: id,
                name: name || `WordPress user #${id}`,
            };
            const state = ensureState(host);
            state.contentAction = "reassign";
            state.destination = destination;
            syncCompatibilityState(host);
            host.delete_error = false;
            host.delete_message = `Ready. All existing content will be assigned to ${destinationLabel(destination)}.`;
            dispatchChange(root, host, destination);
            return true;
        },

        clear(host, root = null, resetSearch = false) {
            const state = ensureState(host);
            state.destination = null;
            syncCompatibilityState(host);
            host.delete_error = false;
            host.delete_message = "Search for and select the WordPress user who should receive all existing content.";
            if (resetSearch) {
                const search = searchData(root);
                if (search && search.component && typeof search.component.clearSelection === "function") {
                    search.component.clearSelection();
                }
            }
            dispatchChange(root, host, null);
        },

        ready(host, excludedUserIds = []) {
            const state = ensureState(host);
            if (state.contextLoaded !== true) return false;
            if (state.requiresReassignment === false) return true;
            if (state.contentAction === "delete") return true;
            const id = candidateId(state.destination);
            return Number.isFinite(id) && id > 0 && !positiveIds(excludedUserIds).includes(id);
        },

        configureSearch(root, host, options = {}) {
            const search = searchData(root);
            if (!search || !search.component) return false;
            const sourceId = Number.parseInt(options.sourceUserId, 10) || "source";
            if (!search.element.dataset.wordpressUserDeletionSearchId) {
                searchSequence += 1;
                search.element.dataset.wordpressUserDeletionSearchId = `wordpress-user-reassignment-${sourceId}-${searchSequence}`;
            }
            search.element.id = search.element.dataset.wordpressUserDeletionSearchId;
            search.component.componentId = search.element.id;

            const excludedUserIds = positiveIds(options.excludedUserIds || []);
            if (typeof search.component.setExtraParams === "function") {
                search.component.setExtraParams({
                    publication_id: options.publicationId || "",
                    exclude_user_ids: excludedUserIds.join(","),
                });
            }
            if (typeof search.component.setExcludeValues === "function") {
                search.component.setExcludeValues(excludedUserIds);
            }
            syncCompatibilityState(host);
            return true;
        },

        alpineMethods() {
            return {
                wpUserDeletionInitialize: (host) => api.initialize(host),
                wpUserDeletionApplyContext: (host, context) => api.applyContext(host, context),
                wpUserDeletionState: (host) => api.state(host),
                wpUserDeletionNeedsReassignment: (host) => api.needsReassignment(host),
                wpUserDeletionNeedsCandidateContext: (host) => api.needsCandidateContext(host),
                wpUserDeletionContentCountLabel: (host) => api.contentCountLabel(host),
                wpUserDeletionContentAction: (host) => api.contentAction(host),
                wpUserDeletionDeletesContent: (host) => api.deletesContent(host),
                wpUserDeletionSetContentAction: (host, action, root) => api.setContentAction(host, action, root),
                wpUserDeletionCandidateGroups: (host) => api.candidateGroups(host),
                wpUserDeletionCandidateMeta: (candidate) => api.candidateMeta(candidate),
                wpUserDeletionSelectedId: (host) => api.selectedId(host),
                wpUserDeletionSelectedLabel: (host) => api.selectedLabel(host),
                wpUserDeletionSelect: (host, candidate, options, root) => api.select(host, candidate, options, root),
                wpUserDeletionClear: (host, root, resetSearch) => api.clear(host, root, resetSearch),
                wpUserDeletionReady: (host, excludedUserIds) => api.ready(host, excludedUserIds),
                wpUserDeletionConfigureSearch: (root, host, options) => api.configureSearch(root, host, options),
            };
        },
    };

    window.HexaWordPressUserDeletion = api;
})(window);
