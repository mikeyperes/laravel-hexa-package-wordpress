@php
    $linkExpr = $linkExpr ?? "row.link";
    $showCompanyPhotoPicker = (bool) ($showCompanyPhotoPicker ?? false);
    $showProfilePhotoWithoutFields = (bool) ($showProfilePhotoWithoutFields ?? false);
@endphp
{{-- Shared WordPress user field bridge panel using the SFPF audit field-workspace interaction model. --}}
                        <div class="jd-field jd-field-gate jd-sfpf-bridge">
                            <div class="jd-tool-head jd-sfpf-bridge-head" data-journalist-profile-fields-header>
                                <div>
                                    <strong>Profile fields</strong>
                                    <div class="jd-row-sub">Compare the connected Notion entity with the live WordPress author, then update only the fields that need attention.</div>
                                </div>
                                <button type="button" class="jd-mini jd-mini-indigo" :disabled="{!! $linkExpr !!}.field_busy" :title="{!! $linkExpr !!}.field_busy ? disabledTitle({!! $linkExpr !!}) : `Load Notion fields for this mapped journalist`" @click.stop="loadFieldBridge({!! $linkExpr !!})" data-sfpf-action="load-journalist-field-bridge">
                                    <span x-show="{!! $linkExpr !!}.field_busy===`load`" class="jd-spin"></span>
                                    <span x-text="{!! $linkExpr !!}.field_busy===`load` ? `Loading fields...` : (fieldBridgeLoaded({!! $linkExpr !!}) ? `Reload fields` : `Load fields`)"></span>
                                </button>
                            </div>
                            <div class="jd-inline-status" x-show="{!! $linkExpr !!}.field_message" :class="{!! $linkExpr !!}.field_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.field_message"></div>
                            <div class="jd-control-reason" x-show="{!! $linkExpr !!}.field_busy" x-text="disabledTitle({!! $linkExpr !!})"></div>
                            <template x-if="fieldBridgeLoaded({!! $linkExpr !!}) && {!! $linkExpr !!}.field_busy !== &quot;load&quot;">
                                <div class="jd-sfpf-workspace">
                                    <div class="jd-sfpf-field-list">
                                        <template x-for="fieldRow in bridgeVisibleRows({!! $linkExpr !!}).filter(r => !r.is_photo_bridge)" :key="fieldRow.key">
                                            <div class="jd-sfpf-field" :class="fieldCardClass(fieldRow)" :data-account-control="fieldAccountControlKind(fieldRow)" x-data="{ open: !fieldMarkedDone(fieldRow) }" x-effect="if(fieldMarkedDone(fieldRow)){ open=false } else if(fieldRow.save_status === `error`){ open=true }">
                                                <div class="jd-sfpf-field-head">
                                                    <div class="jd-sfpf-field-title-block">
                                                        <span x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-done-icon">✓</span>
                                                        <div>
                                                            <div class="jd-sfpf-field-label" x-text="fieldRow.label"></div>
                                                            <div class="jd-sfpf-field-mapline"><span x-text="fieldSourceLabel(fieldRow)"></span><b x-text="fieldRow.notion_field || `—`"></b><span>→ WordPress</span><b x-text="fieldRow.wp_field || `—`"></b></div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-sfpf-field-meta">
                                                        <button type="button" x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-pill jd-sfpf-pill-done" title="Move this field back to review" @click.stop="unmarkBridgeFieldDone(fieldRow); open=true" data-sfpf-action="reopen-field">Completed <span class="jd-sfpf-pill-done-x" aria-hidden="true">&times;</span></button>
                                                        <span x-show="fieldValuesEquivalent(fieldRow) && !fieldRow.save_status" class="jd-sfpf-pill jd-sfpf-pill-sync">in sync</span>
                                                        <span x-show="fieldRow.save_status" class="jd-sfpf-pill jd-sfpf-status-pill" :class="fieldSaveStatusClass(fieldRow)"><span class="jd-spin jd-spin-dark" x-show="fieldRow.save_status === `saving`"></span><span x-show="fieldRow.save_status !== `saving`" x-text="fieldSaveStatusIcon(fieldRow)"></span><span x-text="fieldSaveStatusLabel(fieldRow)"></span></span>
                                                        <button type="button" x-show="!fieldMarkedDone(fieldRow)" class="jd-sfpf-mini jd-sfpf-mini-done" @click.stop="markBridgeFieldDone(fieldRow); open=false" data-sfpf-action="mark-field-completed">Mark completed</button>
                                                        <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" x-text="open ? `Collapse` : `Expand`"></button>
                                                    </div>
                                                </div>
                                                <div x-show="!open" x-cloak class="jd-sfpf-collapsed-summary">
                                                    <div><b x-text="fieldRow.label"></b><span x-text="` · ` + (fieldRow.wp_field || `WordPress field`)"></span></div>
                                                    <div><b x-text="fieldSourceLabel(fieldRow) + `:`"></b> <span x-text="(fieldRow.notion_field || `—`) + ` = ` + fieldValuePreview(fieldRow.notion_value)"></span></div>
                                                    <div><b>WordPress:</b> <span x-text="(fieldRow.wp_field || `—`) + ` = ` + fieldValuePreview(fieldDisplayValue(fieldRow, fieldRow.wp_value, `wordpress`))"></span></div>
                                                    <div x-show="fieldRow.save_status || fieldRow.save_message" x-cloak class="jd-sfpf-collapsed-live" :class="fieldRow.save_status || ``">
                                                        <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                        <span x-show="fieldRow.save_status === `error`" class="jd-field-save-x">&#10005;</span>
                                                        <span x-show="fieldRow.save_status && fieldRow.save_status !== `saving` && fieldRow.save_status !== `error`" class="jd-field-save-ok">&#10003;</span>
                                                        <b x-text="fieldSaveStatusLabel(fieldRow) || `Status`"></b>
                                                        <span x-text="fieldRow.save_message || (fieldRow.save_status === `saving` ? `Saving...` : (fieldRow.save_status === `error` ? `Failed` : `Saved`))"></span>
                                                    </div>
                                                    <template x-if="latestFieldActivity({!! $linkExpr !!}, fieldRow)">
                                                        <div class="jd-sfpf-collapsed-activity" :class="`is-${latestFieldActivity({!! $linkExpr !!}, fieldRow).type}`">
                                                            <span class="jd-activity-dot"></span>
                                                            <span x-text="latestFieldActivity({!! $linkExpr !!}, fieldRow).message"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <template x-if="fieldAccountControlKind(fieldRow) === `role`">
                                                    <div x-show="open" x-cloak class="jd-sfpf-field-body" data-journalist-role-bridge>
                                                        <div class="jd-sfpf-field-row">
                                                            <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                                <div class="jd-sfpf-side-label"><span>ACCOUNT CONTROL</span><b>role</b></div>
                                                                <input type="text" class="jd-cu-input" :value="fieldRow.notion_value || `Managed directly on the WordPress user`" readonly>
                                                            </div>
                                                            <div class="jd-sfpf-arrow">→</div>
                                                            <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                                <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b>role</b></div>
                                                                <select class="jd-cu-input" x-model="{!! $linkExpr !!}.role" :disabled="{!! $linkExpr !!}.role_busy" @change="syncAccountBridgeRow({!! $linkExpr !!}, fieldRow)">
                                                                    <template x-for="role in roleOptions" :key="role.value">
                                                                        <option :value="role.value" x-text="role.label"></option>
                                                                    </template>
                                                                </select>
                                                                <div class="jd-username-help">
                                                                    Current WordPress role: <strong x-text="roleDisplayLabel({!! $linkExpr !!}.role_current || {!! $linkExpr !!}.role)"></strong>
                                                                    <span x-show="roleChanged({!! $linkExpr !!})"> · Selected change: <strong style="color:#3730a3;" x-text="roleDisplayLabel({!! $linkExpr !!}.role)"></strong></span>
                                                                </div>
                                                                <div class="jd-sfpf-side-actions">
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `account_role:wordpress`)" :disabled="{!! $linkExpr !!}.role_busy || !roleChanged({!! $linkExpr !!})" @click.stop="saveAccountRole({!! $linkExpr !!}, fieldRow)" :title="roleChanged({!! $linkExpr !!}) ? `Save the selected WordPress role` : `The selected role is already current`">
                                                                        <span x-show="{!! $linkExpr !!}.role_busy" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.role_busy ? `Saving role...` : `Save role`"></span>
                                                                    </button>
                                                                </div>
                                                                <div class="jd-username-status" :class="{!! $linkExpr !!}.role_error ? `is-error` : ``" x-show="{!! $linkExpr !!}.role_message" x-text="{!! $linkExpr !!}.role_message"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="fieldAccountControlKind(fieldRow) === `username`">
                                                    <div x-show="open" x-cloak class="jd-sfpf-field-body" data-journalist-username-recreate>
                                                        <div class="jd-sfpf-field-row">
                                                            <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                                <div class="jd-sfpf-side-label"><span>SUGGESTED</span><b>username</b></div>
                                                                <input type="text" class="jd-cu-input" :value="linkUsernameSuggestion({!! $linkExpr !!}) || `none`" readonly>
                                                            </div>
                                                            <div class="jd-sfpf-arrow">→</div>
                                                            <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                                <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b>user_login</b></div>
                                                                <input class="jd-cu-input" x-model="{!! $linkExpr !!}.username_new" :disabled="{!! $linkExpr !!}.username_busy" @input="{!! $linkExpr !!}.username_message = ``; {!! $linkExpr !!}.username_error = false; syncAccountBridgeRow({!! $linkExpr !!}, fieldRow)" autocomplete="off">
                                                                <div class="jd-sfpf-side-actions">
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" :disabled="{!! $linkExpr !!}.username_busy || !linkUsernameSuggestion({!! $linkExpr !!})" @click.stop="applyAccountUsernameSuggestion({!! $linkExpr !!}, fieldRow)">Apply suggested</button>
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `account_username:wordpress`)" :disabled="{!! $linkExpr !!}.username_busy || !linkUsernameChanged({!! $linkExpr !!})" @click.stop="recreateAccountUsername({!! $linkExpr !!}, fieldRow)">
                                                                        <span x-show="{!! $linkExpr !!}.username_busy" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.username_busy ? `Replacing...` : `Recreate user with username`"></span>
                                                                    </button>
                                                                </div>
                                                                <div class="jd-username-help">Changing this creates a replacement WordPress user, reassigns content, then deletes the old user.</div>
                                                                <div class="jd-username-status" :class="{!! $linkExpr !!}.username_error ? `is-error` : ``" x-show="{!! $linkExpr !!}.username_message" x-text="{!! $linkExpr !!}.username_message"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                                <div x-show="open && !fieldAccountControlKind(fieldRow)" x-cloak class="jd-sfpf-field-body">
                                                    <div class="jd-sfpf-field-row">
                                                        <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                            <div class="jd-sfpf-side-label"><span>NOTION</span><b x-text="fieldRow.notion_field || `—`"></b></div>
                                                            <textarea x-model="fieldRow.notion_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `notion`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.notion_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `notion`)" ></textarea>
                                                            <div class="jd-sfpf-links" x-show="fieldValueLinks(fieldRow, `notion`).length">
                                                                <template x-for="url in fieldValueLinks(fieldRow, `notion`)" :key="`n-` + url"><a :href="url" target="_blank" rel="noopener" class="jd-link" x-text="url"></a></template>
                                                            </div>
                                                            <div class="jd-sfpf-side-actions">
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-notion" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`)" :disabled="!canSaveNotionRow({!! $linkExpr !!}, fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`) || `Save the current Notion-side input to Notion`" @click.stop="saveBridgeField({!! $linkExpr !!}, fieldRow, `notion`)" data-sfpf-action="manual-save-to-notion">
                                                                    <span class="jd-spin jd-spin-dark" x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `saving`"></span><span>Save to Notion</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `success`">✓</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_notion:notion`) === `error`">×</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="jd-sfpf-arrow">→</div>
                                                        <div class="jd-sfpf-side jd-sfpf-side-wp">
                                                            <div class="jd-sfpf-side-label"><span>WORDPRESS</span><b x-text="fieldRow.wp_field || `—`"></b></div>
                                                            <template x-if="fieldControlKind(fieldRow) !== `boolean`">
                                                                <textarea x-model="fieldRow.wp_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `wordpress`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.wp_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `wordpress`)" ></textarea>
                                                            </template>
                                                            <template x-if="fieldControlKind(fieldRow) === `boolean`">
                                                                <div class="jd-boolean-toggle" role="group" :aria-label="(fieldRow.label || fieldRow.wp_field || `Boolean field`) + ` WordPress value`">
                                                                    <button type="button" :class="fieldBooleanValue(fieldRow.wp_value) ? `is-active` : ``" @click.stop="setBooleanFieldValue({!! $linkExpr !!}, fieldRow, true)">Yes</button>
                                                                    <button type="button" :class="!fieldBooleanValue(fieldRow.wp_value) ? `is-active` : ``" @click.stop="setBooleanFieldValue({!! $linkExpr !!}, fieldRow, false)">No</button>
                                                                    <span x-text="`Currently ` + fieldBooleanLabel(fieldRow.wp_value)"></span>
                                                                </div>
                                                            </template>
                                                            <div class="jd-sfpf-links" x-show="fieldValueLinks(fieldRow, `wordpress`).length">
                                                                <template x-for="url in fieldValueLinks(fieldRow, `wordpress`)" :key="`w-` + url"><a :href="url" target="_blank" rel="noopener" class="jd-link" x-text="url"></a></template>
                                                            </div>
                                                            <div x-show="fieldProposedValue(fieldRow)" class="jd-sfpf-proposal" data-sfpf-field-proposal>
                                                                <div class="jd-sfpf-proposal-top"><span x-text="fieldProposalLabel(fieldRow)"></span><button type="button" @click.stop="denyFieldProposal(fieldRow)" title="Dismiss proposal">×</button></div>
                                                                <div class="jd-sfpf-proposal-value" x-text="fieldProposalDisplayValue(fieldRow)"></div>
                                                                <div class="jd-sfpf-proposal-why" x-show="fieldProposalReason(fieldRow)" x-text="fieldProposalReason(fieldRow)"></div>
                                                                <div class="jd-sfpf-proposal-actions">
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `proposal_approve:wordpress`)" :disabled="fieldTargetBusy({!! $linkExpr !!}, fieldRow, `wordpress`) || !fieldProposedValue(fieldRow)" @click.stop="approveFieldProposal({!! $linkExpr !!}, fieldRow)" data-sfpf-action="approve-field-proposal">Approve</button>
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-danger" :disabled="fieldTargetBusy({!! $linkExpr !!}, fieldRow, `wordpress`)" @click.stop="denyFieldProposal(fieldRow)">Deny</button>
                                                                </div>
                                                            </div>
                                                            <div class="jd-sfpf-side-actions">
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`)" :disabled="!canSaveWordPressRow({!! $linkExpr !!}, fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || `Save the current WordPress-side input to WordPress`" @click.stop="saveBridgeField({!! $linkExpr !!}, fieldRow, `wordpress`)" data-sfpf-action="manual-save-to-wordpress">
                                                                    <span class="jd-spin" x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `saving`"></span><span>Save to WordPress</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `success`">✓</span><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `manual_edit_wp:wordpress`) === `error`">×</span>
                                                                </button>
                                                                <button type="button" class="jd-sfpf-mini jd-sfpf-mini-rich" x-show="fieldControlKind(fieldRow) === `textarea`" @click.stop="toggleBridgeRichEditor($event)">Rich editor</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-sfpf-field-actions">
                                                        <div class="jd-field-save-status" x-show="fieldRow.save_status" x-cloak :class="fieldRow.save_status">
                                                            <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                            <span x-show="fieldRow.save_status === `error`" class="jd-field-save-x">&#10005;</span>
                                                            <span x-show="fieldRow.save_status && fieldRow.save_status !== `saving` && fieldRow.save_status !== `error`" class="jd-field-save-ok">&#10003;</span>
                                                            <span x-text="fieldRow.save_message || (fieldRow.save_status === `saving` ? `Saving...` : (fieldRow.save_status === `error` ? `Failed` : `Saved`))"></span>
                                                        </div>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ghost" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `refresh_live:both`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || fieldRowBusy({!! $linkExpr !!}, fieldRow)" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (fieldRowBusyReason({!! $linkExpr !!}, fieldRow) || `Re-pull this field from live Notion and WordPress`)" @click.stop="refreshBridgeField({!! $linkExpr !!}, fieldRow)" data-sfpf-action="refresh-live-field">Reset from live</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_notion_to_wp:wordpress`)" :disabled="!!fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldValuesEquivalent(fieldRow)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || (fieldValuesEquivalent(fieldRow) ? `Already identical — nothing to copy.` : `Copy the Notion value into WordPress`)" @click.stop="copySingleFieldFromNotion({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-np-to-wp">Copy Notion to WordPress</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-notion" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_wp_to_notion:notion`)" :disabled="!!copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow) || fieldValuesEquivalent(fieldRow)" :title="copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow) || (fieldValuesEquivalent(fieldRow) ? `Already identical — nothing to copy.` : `Copy the WordPress value into Notion`)" @click.stop="copySingleFieldFromWordPress({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-wp-to-notion">Copy WordPress to Notion</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ai" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `ai_propose:field`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI proposals are inactive because no AI model is configured.` : `Ask AI to propose a value for this field`)" @click.stop="proposeAi({!! $linkExpr !!}, fieldRow.key)" data-sfpf-action="ai-propose-field"><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_propose:field`) === `saving`" class="jd-spin jd-spin-dark"></span><span x-text="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_propose:field`) === `saving` ? `Proposing...` : `Propose with AI`"></span></button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ai" x-show="fieldSupportsWebAi(fieldRow)" x-cloak :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI web search is inactive because no AI model is configured.` : `Search the web with AI and propose a researched value for this field`)" @click.stop="proposeWebAi({!! $linkExpr !!}, fieldRow.key)" data-sfpf-action="ai-web-propose-field"><span x-show="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`) === `saving`" class="jd-spin jd-spin-dark"></span><span x-text="fieldButtonStatus({!! $linkExpr !!}, fieldRow, `ai_web_propose:field`) === `saving` ? `Searching...` : `Search web with AI`"></span></button>
                                                    </div>
                                                    <div class="jd-sfpf-save-structure" data-hexa-loading-type="3" x-show="fieldRow.save_status || latestFieldActivity({!! $linkExpr !!}, fieldRow)" x-cloak :class="fieldSaveStructureClass(fieldRow)">
                                                        <div class="jd-sfpf-save-structure-top">
                                                            <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                            <span x-show="fieldRow.save_status === `success`" class="jd-field-save-ok">&#10003;</span>
                                                            <span x-show="fieldRow.save_status === `error`" class="jd-field-save-x">&#10005;</span>
                                                            <strong x-text="fieldSaveStructureTitle(fieldRow)"></strong>
                                                        </div>
                                                        <div class="jd-sfpf-save-track"><span :style="`width:${fieldSaveStructureProgress(fieldRow)}%`"></span></div>
                                                        <div class="jd-sfpf-save-structure-detail" x-text="fieldSaveStructureDetail({!! $linkExpr !!}, fieldRow)"></div>
                                                        <template x-if="latestFieldActivity({!! $linkExpr !!}, fieldRow)">
                                                            <div class="jd-sfpf-save-activity" x-text="latestFieldActivity({!! $linkExpr !!}, fieldRow).message"></div>
                                                        </template>
                                                    </div>
                                                    <div class="jd-control-reason" x-show="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)" x-text="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="jd-profile-photo-card jd-bridge-profile-photo-card" :class="profilePhotoCardClass({!! $linkExpr !!})" @if(!$showProfilePhotoWithoutFields) x-show="bridgePhotoRows({!! $linkExpr !!}).length" @endif x-data="{ open: !profilePhotoBridgeDone({!! $linkExpr !!}) }" x-init="$nextTick(() => { if(!{!! $linkExpr !!}.photo_scanned_once && bridgePhotoRows({!! $linkExpr !!}).length){ {!! $linkExpr !!}.photo_scanned_once = true; scanPhotos({!! $linkExpr !!}); } })" x-effect="if(profilePhotoBridgeDone({!! $linkExpr !!})){ open=false } else if(profilePhotoBridgeHasError({!! $linkExpr !!})){ open=true }" data-journalist-profile-photo-bridge>
                                        <div class="jd-profile-photo-head">
                                            <div class="jd-sfpf-field-title-block"><span x-show="profilePhotoBridgeDone({!! $linkExpr !!})" class="jd-sfpf-done-icon">✓</span><div><div class="jd-profile-photo-title">Profile photo</div><div class="jd-profile-photo-note">Review the current WordPress portrait, upload a replacement, or choose one from the connected photo sources.</div></div></div>
                                            <div class="jd-profile-photo-controls">
                                                <div class="jd-sfpf-field-meta">
                                                    <button type="button" x-show="profilePhotoBridgeDone({!! $linkExpr !!})" class="jd-sfpf-pill jd-sfpf-pill-done" title="Move profile photo back to review" :disabled="{!! $linkExpr !!}.photo_completion_busy === true" @click.stop="setProfilePhotoBridgeDone({!! $linkExpr !!}, false); open=true">Completed <span class="jd-sfpf-pill-done-x" aria-hidden="true">&times;</span></button>
                                                    <span x-show="profilePhotoBridgeInSync({!! $linkExpr !!}) && !profilePhotoBridgeStatus({!! $linkExpr !!})" class="jd-sfpf-pill jd-sfpf-pill-sync">in sync</span>
                                                    <span x-show="profilePhotoBridgeStatus({!! $linkExpr !!})" class="jd-sfpf-pill jd-sfpf-status-pill" :class="`is-${profilePhotoBridgeStatus({!! $linkExpr !!})}`"><span class="jd-spin jd-spin-dark" x-show="profilePhotoBridgeStatus({!! $linkExpr !!}) === `saving`"></span><span x-show="profilePhotoBridgeStatus({!! $linkExpr !!}) !== `saving`" x-text="profilePhotoBridgeStatusIcon({!! $linkExpr !!})"></span><span x-text="profilePhotoBridgeStatusLabel({!! $linkExpr !!})"></span></span>
                                                    <button type="button" x-show="!profilePhotoBridgeDone({!! $linkExpr !!})" class="jd-sfpf-mini jd-sfpf-mini-done" :disabled="{!! $linkExpr !!}.photo_completion_busy === true" @click.stop="setProfilePhotoBridgeDone({!! $linkExpr !!}, true); open=false">Mark completed</button>
                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost jd-profile-photo-toggle" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" data-profile-photo-toggle><span class="jd-profile-photo-toggle-caret" :class="open ? `is-open` : ``" aria-hidden="true">&#9656;</span><span x-text="open ? `Collapse photo tools` : `Expand photo tools`"></span></button>
                                                </div>
                                                <div class="jd-gallery-actions">
                                                    <label class="jd-mini jd-mini-indigo" :style="photoMutationBusy({!! $linkExpr !!}) ? `opacity:.55;cursor:default` : ``" @click.stop data-journalist-action="upload-bridge-profile-photo"><span x-show="{!! $linkExpr !!}.photo_busy===`upload`" class="jd-spin"></span><span x-text="{!! $linkExpr !!}.photo_busy===`upload` ? `Uploading...` : `Upload photo`"></span><input type="file" accept="image/*" style="display:none" :disabled="photoMutationBusy({!! $linkExpr !!})" @change="uploadPhotoFile({!! $linkExpr !!}, $event, bridgeDefaultPhotoField({!! $linkExpr !!}))"></label>
                                                    @if($showCompanyPhotoPicker)
                                                        <button type="button" class="jd-mini jd-mini-ghost" :disabled="photoScanBusy({!! $linkExpr !!})" @click.stop="scanCompanyPhotos({!! $linkExpr !!})" data-journalist-action="pick-company-photos"><span x-show="photoBusyValue({!! $linkExpr !!})===`scan-master`" class="jd-spin jd-spin-dark"></span><span x-text="photoBusyValue({!! $linkExpr !!})===`scan-master` ? `Loading company photos...` : `Company photos`"></span></button>
                                                    @endif
                                                    <button type="button" class="jd-mini jd-mini-ghost" :disabled="photoScanBusy({!! $linkExpr !!})" @click.stop="scanPhotos({!! $linkExpr !!})" data-journalist-action="load-bridge-folder-photos"><span x-show="photoScanBusy({!! $linkExpr !!})" class="jd-spin jd-spin-dark"></span><span x-text="photoScanBusy({!! $linkExpr !!}) ? `Scanning...` : `Scan sources`"></span></button>
                                                    @if(Route::has('google-drive.photo-renamer'))
                                                        <a href="{{ route('google-drive.photo-renamer') }}" target="_blank" rel="noopener" class="jd-mini jd-mini-ghost" @click.stop>Photo renamer <span aria-hidden="true">&#8599;</span></a>
                                                    @else
                                                        <a href="/google-drive/photo-renamer" target="_blank" rel="noopener" class="jd-mini jd-mini-ghost" @click.stop>Photo renamer <span aria-hidden="true">&#8599;</span></a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div x-show="!open" x-cloak class="jd-profile-photo-summary">
                                            <div><b>Profile photo</b><span x-text="profilePhotoUrl({!! $linkExpr !!}) ? ` · WordPress avatar set` : ` · no WordPress avatar set`"></span></div>
                                            <div><b>WordPress:</b> <span x-text="profilePhotoSource({!! $linkExpr !!})"></span></div>
                                            <div x-show="{!! $linkExpr !!}.photo_message"><b>Status:</b> <span x-text="{!! $linkExpr !!}.photo_message"></span></div>
                                        </div>
                                        <div x-show="open" x-cloak class="jd-profile-photo-body">
                                            <div class="jd-current-profile-photo" x-show="profilePhotoUrl({!! $linkExpr !!})" x-cloak>
                                                <img class="jd-current-profile-photo-img" :src="profilePhotoUrl({!! $linkExpr !!})" :alt="`Current WordPress profile photo for ${{!! $linkExpr !!}.wp_name || {!! $linkExpr !!}.profile_name || `journalist`}`" loading="eager" decoding="async" x-on:error="markDeadProfilePhoto({!! $linkExpr !!}, $event)" data-profile-photo-thumbnail>
                                                <div class="jd-current-profile-photo-meta">
                                                    <div class="vp-source-field-title">Current WordPress profile photo</div>
                                                    <div class="vp-meta" x-text="profilePhotoSource({!! $linkExpr !!})"></div>
                                                    <div class="vp-meta" x-show="{!! $linkExpr !!}.profile_photo && {!! $linkExpr !!}.profile_photo.source_url" x-text="`Source: ` + {!! $linkExpr !!}.profile_photo.source_url"></div>
                                                    <a class="jd-link" :href="profilePhotoFullUrl({!! $linkExpr !!})" target="_blank" rel="noopener noreferrer">Open full image &#8599;</a>
                                                </div>
                                            </div>
                                            <div x-data="{
                                                notionMediaConfig:{
                                                    hooks:{
                                                        detectOnlineImage:(payload = {}) => detectBridgeOnlineImage({!! $linkExpr !!}, payload || {}),
                                                        uploadMediaFile:(payload = {}) => uploadBridgeMediaFile({!! $linkExpr !!}, payload || {}),
                                                        uploadFieldPhoto:(payload = {}) => uploadBridgeMediaFile({!! $linkExpr !!}, payload || {}),
                                                        mergeMediaCandidates:(payload = {}) => bridgeMediaMergeCandidates({!! $linkExpr !!}, payload.candidates || []),
                                                        applyProfilePhoto:(payload = {}) => bridgeMediaApplyPhoto({!! $linkExpr !!}, payload.photo, payload.item || null)
                                                    }
                                                },
                                                activeSourceId(){ return {!! $linkExpr !!}.id || null },
                                                isBusy(key){ return bridgeMediaBusy({!! $linkExpr !!}, key) },
                                                notionGalleryPhoto(){ return bridgeMediaGalleryPhoto({!! $linkExpr !!}) },
                                                notionGalleryKey(photo){ return bridgeMediaGalleryKey({!! $linkExpr !!}, photo) },
                                                notionGalleryPhotos(photo){ return bridgeMediaGalleryPhotos({!! $linkExpr !!}, photo) },
                                                notionPhotoRows(){ return bridgeMediaPhotoRows({!! $linkExpr !!}) },
                                                notionPhotoSources(row){ return bridgeMediaPhotoSources({!! $linkExpr !!}, row) },
                                                notionPhotoSourceKey(row, photo){ return bridgeMediaPhotoSourceKey({!! $linkExpr !!}, row, photo) },
                                                notionPhotoCurrentUrl(photo){ return bridgeMediaPhotoCurrentUrl({!! $linkExpr !!}, photo) },
                                                notionPhotoUrlDraft(photo){ return bridgeMediaPhotoCurrentUrl({!! $linkExpr !!}, photo) },
                                                setNotionPhotoUrlDraft(photo, value){ return bridgeMediaSetPhotoUrl({!! $linkExpr !!}, photo, value) },
                                                notionPhotoActionKey(photo, action){ return bridgeMediaPhotoActionKey({!! $linkExpr !!}, photo, action) },
                                                notionPhotoLooksLikeDriveFolder(photo){ return bridgeMediaPhotoLooksLikeDriveFolder({!! $linkExpr !!}, photo) },
                                                photoImageUrl(photo){ return bridgeMediaPhotoImageUrl({!! $linkExpr !!}, photo) },
                                                saveNotionPhotoUrl(photo){ return bridgeMediaSaveNotionPhotoUrl({!! $linkExpr !!}, photo) },
                                                loadNotionGalleryPhotos(photo){ return bridgeMediaLoadNotionGalleryPhotos({!! $linkExpr !!}, photo) },
                                                loadAllNotionPhotoSources(){ return scanPhotos({!! $linkExpr !!}) },
                                                copyNotionPhotoUrl(photo){ return copyText(bridgeMediaPhotoCurrentUrl({!! $linkExpr !!}, photo)) },
                                                unifiedProfilePhotoCandidates(){ return bridgeMediaUnifiedCandidates({!! $linkExpr !!}) },
                                                unifiedProfilePhotoSelectedCount(){ return bridgeMediaSelectedCount({!! $linkExpr !!}) },
                                                selectAllUnifiedProfilePhotoCandidates(){ return bridgeMediaSelectAll({!! $linkExpr !!}) },
                                                clearUnifiedProfilePhotoSelection(){ return bridgeMediaClearSelection({!! $linkExpr !!}) },
                                                notionGallerySelected(photo, item){ return bridgeMediaSelected({!! $linkExpr !!}, photo, item) },
                                                notionGalleryItemMissing(item, photo){ return !!(item && (item._dead || item._preview_failed)) },
                                                toggleNotionGalleryPhoto(photo, item){ return bridgeMediaToggleSelection({!! $linkExpr !!}, photo, item) },
                                                galleryItemImageUrl(item){ return photoCandidateImageUrl({!! $linkExpr !!}, item) },
                                                galleryItemOpenUrl(item){ return bridgeMediaItemOpenUrl(item) },
                                                galleryPhotoName(item){ return bridgeMediaItemName(item) },
                                                markDeadGalleryImage(item, event){ return handlePhotoCandidateImageError({!! $linkExpr !!}, item, event) },
                                                photoNotionField(item, photo){ return bridgeMediaItemNotionField(item, photo) },
                                                photoResolutionStatus(item, photo){ return bridgeMediaResolutionStatus(item, photo) },
                                                photoAspectStatus(item, photo){ return bridgeMediaAspectStatus(item, photo) },
                                                photoSizeText(item, photo){ return bridgeMediaSizeText(item, photo) },
                                                photoCandidateDetailRows(item, photo){ return bridgeMediaDetailRows(item, photo) },
                                                photoCandidateWarnings(item){ return bridgeMediaWarnings(item) },
                                                notionPhotoApplyKey(photo, item){ return bridgeMediaApplyKey({!! $linkExpr !!}, photo, item) }
                                            }" style="min-width:0;">
                                                @include("notion::partials.media-gallery", [
                                                    "title" => "Generic profile photo gallery bridge",
                                                    "intro" => "Reusable gallery bridge for Notion photo sources, Google Drive folders, online image detection, upload, and profile photo selection.",
                                                    "fieldTitle" => "Notion photo sources",
                                                    "fieldNote" => "Each mapped Notion photo field can hold an image URL or a Google Drive folder. Drive folders load into the unified picker.",
                                                    "candidateTitle" => "Candidates - pick one for the WordPress profile photo",
                                                    "candidateNote" => "All loaded Notion photo sources, uploaded images, detected online images, and Google Drive folders are combined here.",
                                                    "emptyCandidateText" => "No candidate photos loaded yet. Scan a Drive folder, detect an online image, or upload an image.",
                                                    "showWordPressGallery" => false,
                                                    "showDirectPhotoUpload" => true,
                                                    "showAddToWordPressGallery" => false,
                                                    "showDirectUrlProfileAction" => true,
                                                    "showDriveSharingHelper" => false,
                                                    "showOnlineImageDetector" => true,
                                                    "showGenericUploadDropzone" => true,
                                                    "showGenericMediaActivity" => true,
                                                ])
                                            </div>
                                        </div>
                                    </div>
                                    <div class="jd-sfpf-actions" data-testid="journalist-sfpf-field-actions">
                                        <button type="button" class="jd-sfpf-btn jd-sfpf-btn-copy" :class="workspaceButtonClass({!! $linkExpr !!}, `transfer`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !bridgeWritableRows({!! $linkExpr !!}).length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!bridgeWritableRows({!! $linkExpr !!}).length ? `No writable Notion to WordPress fields are available.` : `Copy all writable Notion values to WordPress`)" @click.stop="transferCardFromNotion({!! $linkExpr !!})" data-sfpf-action="journalist-copy-notion-to-wordpress">
                                            <span class="jd-spin" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `saving`"></span>
                                            <span>Copy Notion to WordPress</span>
                                            <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `success`">✓</span>
                                            <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `transfer`) === `error`">×</span>
                                        </button>
                                        <div class="jd-sfpf-ai-control">
                                            <button type="button" class="jd-sfpf-btn jd-sfpf-btn-ai" :class="workspaceButtonClass({!! $linkExpr !!}, `ai`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI proposals are inactive because no AI model is configured.` : `Generate field proposals from the loaded bridge values`)" @click.stop="proposeAi({!! $linkExpr !!})" data-sfpf-action="journalist-propose-with-ai">
                                                <span class="jd-spin" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `saving`"></span>
                                                <span>Propose with AI</span>
                                                <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `success`">✓</span>
                                                <span class="jd-sfpf-icon" x-show="workspaceButtonStatus({!! $linkExpr !!}, `ai`) === `error`">×</span>
                                            </button>
                                            <span class="jd-sfpf-agent-label">AI model for this card</span>
                                            <select class="jd-sfpf-agent-select" x-model="{!! $linkExpr !!}.ai_agent" @focus.stop="ensureAiState({!! $linkExpr !!})" @change.stop="ensureAiState({!! $linkExpr !!})" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" data-sfpf-action="card-ai-agent-select">
                                                <option value="">Default AI agent</option>
                                                <template x-for="agent in aiOptions" :key="agent.id">
                                                    <option :value="agent.id" x-text="agent.label"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <button type="button" class="jd-sfpf-btn jd-sfpf-btn-edit" :class="{!! $linkExpr !!}.manual_open ? `is-active` : ``" @click.stop="{!! $linkExpr !!}.manual_open = !{!! $linkExpr !!}.manual_open" data-sfpf-action="journalist-ai-notes-toggle">
                                            <span x-text="{!! $linkExpr !!}.manual_open ? `Hide AI notes` : `AI notes`"></span>
                                        </button>
                                        <button type="button" x-show="bridgeCardBusy({!! $linkExpr !!})" class="jd-sfpf-btn jd-sfpf-btn-cancel" @click.stop="cancelBridgeWork({!! $linkExpr !!})" data-sfpf-action="journalist-cancel-field-work">Cancel</button>
                                    </div>
                                    <div class="jd-sfpf-message" x-show="{!! $linkExpr !!}.workspace_message" :class="{!! $linkExpr !!}.workspace_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.workspace_message"></div>
                                    <div class="jd-sfpf-notes" x-show="{!! $linkExpr !!}.manual_open">
                                        <textarea class="jd-ai-notes" x-model="{!! $linkExpr !!}.ai_instructions" placeholder="Optional AI notes or unstructured instructions for this card..."></textarea>
                                    </div>

                                </div>
                            </template>
                        </div>
