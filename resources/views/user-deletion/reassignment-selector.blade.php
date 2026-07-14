@php
    $stateExpr = $stateExpr ?? 'state';
    $publicationExpr = $publicationExpr ?? 'publication';
    $excludedUserIdsExpr = $excludedUserIdsExpr ?? '[]';
    $busyExpr = $busyExpr ?? 'false';
    $searchUrl = $searchUrl ?? '';
    $classPrefix = trim((string) ($classPrefix ?? 'wpud')) ?: 'wpud';
    $searchId = 'wordpress-user-reassignment-' . uniqid();
@endphp

<div class="{{ $classPrefix }}-delete-reassignment"
    x-data="{}"
    x-show="wpUserDeletionState({{ $stateExpr }}).contextLoaded && wpUserDeletionNeedsReassignment({{ $stateExpr }})"
    x-init="$nextTick(() => wpUserDeletionConfigureSearch($root, {{ $stateExpr }}, { sourceUserId: {{ $stateExpr }}.wp_user_id, publicationId: {{ $publicationExpr }}.id, excludedUserIds: {{ $excludedUserIdsExpr }} }))"
    x-cloak>
    <div class="{{ $classPrefix }}-delete-reassignment-label">Assign all existing content to</div>
    <div class="{{ $classPrefix }}-delete-suggestions" x-show="wpUserDeletionCandidateGroups({{ $stateExpr }}).length" x-cloak>
        <template x-for="group in wpUserDeletionCandidateGroups({{ $stateExpr }})" :key="group.key">
            <div class="{{ $classPrefix }}-delete-suggestion-group">
                <div class="{{ $classPrefix }}-delete-suggestion-title" x-text="group.label"></div>
                <div class="{{ $classPrefix }}-delete-suggestion-list">
                    <template x-for="candidate in group.items" :key="candidate.key || candidate.wp_user_id || candidate.id">
                        <button type="button"
                            class="{{ $classPrefix }}-delete-suggestion"
                            :class="String(candidate.wp_user_id || candidate.id) === String(wpUserDeletionSelectedId({{ $stateExpr }}) || '') ? 'is-selected' : ''"
                            :disabled="{{ $busyExpr }}"
                            @click="wpUserDeletionSelect({{ $stateExpr }}, candidate, { publicationId: {{ $publicationExpr }}.id, publicationLabel: {{ $publicationExpr }}.label, excludedUserIds: {{ $excludedUserIdsExpr }} }, $root)">
                            <span x-text="candidate.name || candidate.user_login || ('WP #' + (candidate.wp_user_id || candidate.id))"></span>
                            <small x-text="wpUserDeletionCandidateMeta(candidate)"></small>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <div class="{{ $classPrefix }}-delete-search"
        data-wordpress-user-reassignment-search
        x-show="!wpUserDeletionSelectedId({{ $stateExpr }})"
        x-cloak
        @hexa-search-focus.stop="wpUserDeletionConfigureSearch($root, {{ $stateExpr }}, { sourceUserId: {{ $stateExpr }}.wp_user_id, publicationId: {{ $publicationExpr }}.id, excludedUserIds: {{ $excludedUserIdsExpr }} })"
        @hexa-search-selected.stop="wpUserDeletionSelect({{ $stateExpr }}, $event.detail.item || {}, { publicationId: {{ $publicationExpr }}.id, publicationLabel: {{ $publicationExpr }}.label, excludedUserIds: {{ $excludedUserIdsExpr }} }, $root)"
        @hexa-search-cleared.stop="wpUserDeletionClear({{ $stateExpr }}, $root, false)">
        <x-hexa-smart-search
            :id="$searchId"
            :url="$searchUrl"
            placeholder="Search by name, email, or username..."
            display-field="name"
            subtitle-field="meta"
            value-field="wp_user_id"
            :min-chars="2"
            :debounce="250"
            :max-results="10"
            :show-selection="false"
            :clear-on-select="false" />
    </div>

    <div class="{{ $classPrefix }}-delete-selected" x-show="wpUserDeletionSelectedId({{ $stateExpr }})" x-cloak>
        <span>All existing content will move to <strong x-text="wpUserDeletionSelectedLabel({{ $stateExpr }})"></strong></span>
        <button type="button" class="{{ $classPrefix }}-mini {{ $classPrefix }}-mini-ghost" :disabled="{{ $busyExpr }}" @click="wpUserDeletionClear({{ $stateExpr }}, $root, true)">Change</button>
    </div>
</div>
