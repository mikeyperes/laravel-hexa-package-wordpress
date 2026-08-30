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
    <div class="{{ $classPrefix }}-delete-reassignment-label">Choose what happens to existing content</div>
    <div class="{{ $classPrefix }}-delete-content-actions" data-wordpress-user-content-action>
        <button type="button"
            class="{{ $classPrefix }}-delete-content-action"
            :class="wpUserDeletionContentAction({{ $stateExpr }}) === `reassign` ? `is-selected` : ``"
            :disabled="{{ $busyExpr }}"
            @click="wpUserDeletionSetContentAction({{ $stateExpr }}, `reassign`, $root)">
            <span>Reassign content</span>
            <small>Keep existing content and move it to another WordPress user.</small>
        </button>
        <button type="button"
            class="{{ $classPrefix }}-delete-content-action is-danger"
            :class="wpUserDeletionContentAction({{ $stateExpr }}) === `delete` ? `is-selected` : ``"
            :disabled="{{ $busyExpr }}"
            data-wordpress-delete-content-choice
            @click="wpUserDeletionSetContentAction({{ $stateExpr }}, `delete`, $root)">
            <span>Delete content</span>
            <small>Permanently delete every content item owned by this user.</small>
        </button>
    </div>
    <div class="{{ $classPrefix }}-delete-suggestions" x-show="wpUserDeletionCandidateGroups({{ $stateExpr }}).length && wpUserDeletionContentAction({{ $stateExpr }}) === `reassign`" x-cloak>
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
        x-show="!wpUserDeletionSelectedId({{ $stateExpr }}) && wpUserDeletionContentAction({{ $stateExpr }}) === `reassign`"
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

    <div class="{{ $classPrefix }}-delete-selected" x-show="wpUserDeletionSelectedId({{ $stateExpr }}) && wpUserDeletionContentAction({{ $stateExpr }}) === `reassign`" x-cloak>
        <span>All existing content will move to <strong x-text="wpUserDeletionSelectedLabel({{ $stateExpr }})"></strong></span>
        <button type="button" class="{{ $classPrefix }}-mini {{ $classPrefix }}-mini-ghost" :disabled="{{ $busyExpr }}" @click="wpUserDeletionClear({{ $stateExpr }}, $root, true)">Change</button>
    </div>
    <div class="{{ $classPrefix }}-delete-content-warning"
        x-show="wpUserDeletionContentAction({{ $stateExpr }}) === `delete`"
        x-cloak>
        <strong>Delete all owned content</strong>
        <span><span x-text="wpUserDeletionContentCountLabel({{ $stateExpr }})"></span> will be permanently deleted with this user. WordPress rechecks the count before deletion.</span>
    </div>
</div>
