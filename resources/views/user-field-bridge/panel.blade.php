@php $linkExpr = $linkExpr ?? "row.link"; @endphp
{{-- Shared WordPress user field bridge panel using the SFPF audit field-workspace interaction model. --}}
                        <div class="jd-field jd-field-gate jd-sfpf-bridge">
                            <div class="jd-tool-head jd-sfpf-bridge-head">
                                <div>
                                    <strong>Notion / WordPress field bridge</strong>
                                    <div class="jd-row-sub">Load live Notion and WordPress values, then use the same transfer, AI proposal, and manual-save workflow as SFPF.</div>
                                </div>
                                <button type="button" class="jd-mini jd-mini-indigo" :disabled="{!! $linkExpr !!}.field_busy" :title="{!! $linkExpr !!}.field_busy ? disabledTitle({!! $linkExpr !!}) : `Load Notion fields for this mapped journalist`" @click.stop="loadFieldBridge({!! $linkExpr !!})" data-sfpf-action="load-journalist-field-bridge">
                                    <span x-show="{!! $linkExpr !!}.field_busy===`load`" class="jd-spin"></span>
                                    <span x-text="{!! $linkExpr !!}.field_busy===`load` ? `Loading...` : (fieldBridgeLoaded({!! $linkExpr !!}) ? `Reload fields` : `Load Notion Fields`)"></span>
                                </button>
                            </div>
                            <div class="jd-inline-status" x-show="{!! $linkExpr !!}.field_message" :class="{!! $linkExpr !!}.field_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.field_message"></div>
                            <div class="jd-control-reason" x-show="{!! $linkExpr !!}.field_busy" x-text="disabledTitle({!! $linkExpr !!})"></div>
                            <template x-if="fieldBridgeLoaded({!! $linkExpr !!})">
                                <div class="jd-sfpf-workspace">
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
                                        <button type="button" class="jd-sfpf-btn jd-sfpf-btn-edit" :class="{!! $linkExpr !!}.manual_open ? `is-active` : ``" @click.stop="{!! $linkExpr !!}.manual_open = !{!! $linkExpr !!}.manual_open" data-sfpf-action="journalist-edit-manually">
                                            <span x-text="{!! $linkExpr !!}.manual_open ? `Manual editing on` : `Edit manually`"></span>
                                        </button>
                                        <button type="button" x-show="bridgeCardBusy({!! $linkExpr !!})" class="jd-sfpf-btn jd-sfpf-btn-cancel" @click.stop="cancelBridgeWork({!! $linkExpr !!})" data-sfpf-action="journalist-cancel-field-work">Cancel</button>
                                    </div>
                                    <div class="jd-sfpf-message" x-show="{!! $linkExpr !!}.workspace_message" :class="{!! $linkExpr !!}.workspace_error ? `is-error` : ``" x-text="{!! $linkExpr !!}.workspace_message"></div>
                                    <div class="jd-sfpf-notes" x-show="{!! $linkExpr !!}.manual_open">
                                        <textarea class="jd-ai-notes" x-model="{!! $linkExpr !!}.ai_instructions" placeholder="Optional AI notes or unstructured instructions for this card..."></textarea>
                                    </div>
                                    <div class="jd-sfpf-field-list">
                                        <template x-for="fieldRow in bridgeVisibleRows({!! $linkExpr !!})" :key="fieldRow.key">
                                            <div class="jd-sfpf-field" :class="fieldCardClass(fieldRow)" x-data="{ open: !fieldMarkedDone(fieldRow) }" x-effect="if(fieldMarkedDone(fieldRow)){ open=false }">
                                                <div class="jd-sfpf-field-head">
                                                    <div class="jd-sfpf-field-title-block">
                                                        <span x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-done-icon">✓</span>
                                                        <div>
                                                            <div class="jd-sfpf-field-label" x-text="fieldRow.label"></div>
                                                            <div class="jd-sfpf-field-mapline"><span>Notion</span><b x-text="fieldRow.notion_field || `—`"></b><span>→ WordPress</span><b x-text="fieldRow.wp_field || `—`"></b></div>
                                                        </div>
                                                    </div>
                                                    <div class="jd-sfpf-field-meta">
                                                        <span x-show="fieldMarkedDone(fieldRow)" class="jd-sfpf-pill jd-sfpf-pill-done">Completed</span>
                                                        <span x-show="fieldValuesEquivalent(fieldRow) && !fieldRow.save_status" class="jd-sfpf-pill jd-sfpf-pill-sync">in sync</span>
                                                        <span class="jd-sfpf-pill" x-text="fieldRow.wp_type || `native`"></span>
                                                        <span x-show="fieldRow.save_status === `saving`" class="jd-spin jd-spin-dark"></span>
                                                        <span x-show="fieldRow.save_status" :class="fieldRow.save_status === `error` ? `jd-sfpf-status-error` : `jd-sfpf-status-ok`" x-text="fieldRow.save_message || ``"></span>
                                                        <button type="button" x-show="!fieldMarkedDone(fieldRow)" class="jd-sfpf-mini jd-sfpf-mini-done" @click.stop="markBridgeFieldDone(fieldRow); open=false" data-sfpf-action="mark-field-completed">Mark completed</button>
                                                        <button type="button" class="jd-sfpf-mini jd-sfpf-mini-ghost" @click.stop="open = !open" :aria-expanded="open ? `true` : `false`" x-text="open ? `Collapse` : `Expand`"></button>
                                                    </div>
                                                </div>
                                                <div x-show="!open" x-cloak class="jd-sfpf-collapsed-summary">
                                                    <div><b x-text="fieldRow.label"></b><span x-text="` · ` + (fieldRow.wp_field || `WordPress field`)"></span></div>
                                                    <div><b>Notion:</b> <span x-text="(fieldRow.notion_field || `—`) + ` = ` + fieldValuePreview(fieldRow.notion_value)"></span></div>
                                                    <div><b>WordPress:</b> <span x-text="(fieldRow.wp_field || `—`) + ` = ` + fieldValuePreview(fieldRow.wp_value)"></span></div>
                                                </div>
                                                <div x-show="open" x-cloak class="jd-sfpf-field-body">
                                                    <div class="jd-sfpf-field-row">
                                                        <div class="jd-sfpf-side jd-sfpf-side-notion">
                                                            <div class="jd-sfpf-side-label"><span>NOTION</span><b x-text="fieldRow.notion_field || `—`"></b></div>
                                                            <textarea x-model="fieldRow.notion_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `notion`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.notion_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `notion`)" :readonly="!{!! $linkExpr !!}.manual_open"></textarea>
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
                                                            <textarea x-model="fieldRow.wp_value" @input="markBridgeFieldDirty({!! $linkExpr !!}, fieldRow, `wordpress`, $event.target.value); resizeBridgeTextArea($event.target)" x-init="$nextTick(() => resizeBridgeTextArea($el))" x-effect="fieldRow.wp_value; $nextTick(() => resizeBridgeTextArea($el))" class="jd-sfpf-input" rows="1" :style="fieldInputStyle(fieldRow, `wordpress`)" :readonly="!{!! $linkExpr !!}.manual_open"></textarea>
                                                            <div class="jd-sfpf-links" x-show="fieldValueLinks(fieldRow, `wordpress`).length">
                                                                <template x-for="url in fieldValueLinks(fieldRow, `wordpress`)" :key="`w-` + url"><a :href="url" target="_blank" rel="noopener" class="jd-link" x-text="url"></a></template>
                                                            </div>
                                                            <div x-show="fieldProposedValue(fieldRow)" class="jd-sfpf-proposal" data-sfpf-field-proposal>
                                                                <div class="jd-sfpf-proposal-top"><span x-text="fieldProposalLabel(fieldRow)"></span><button type="button" @click.stop="denyFieldProposal(fieldRow)" title="Dismiss proposal">×</button></div>
                                                                <div class="jd-sfpf-proposal-value" x-text="fieldProposedValue(fieldRow)"></div>
                                                                <div class="jd-sfpf-proposal-why" x-show="fieldRow.ai_rationale" x-text="fieldRow.ai_rationale"></div>
                                                                <div class="jd-sfpf-proposal-actions">
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `proposal_approve:wordpress`)" :disabled="fieldRow.save_status === `saving` || !fieldProposedValue(fieldRow)" @click.stop="approveFieldProposal({!! $linkExpr !!}, fieldRow)" data-sfpf-action="approve-field-proposal">Approve</button>
                                                                    <button type="button" class="jd-sfpf-mini jd-sfpf-mini-danger" :disabled="fieldRow.save_status === `saving`" @click.stop="denyFieldProposal(fieldRow)">Deny</button>
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
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ghost" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `refresh_live:both`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || fieldRowBusy({!! $linkExpr !!}, fieldRow)" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (fieldRowBusyReason({!! $linkExpr !!}, fieldRow) || `Re-pull this field from live Notion and WordPress`)" @click.stop="refreshBridgeField({!! $linkExpr !!}, fieldRow)" data-sfpf-action="refresh-live-field">Reset from live</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-wp" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_notion_to_wp:wordpress`)" :disabled="!!fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`)" :title="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || `Copy the Notion value into WordPress`" @click.stop="copySingleFieldFromNotion({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-np-to-wp">Copy Notion to WordPress</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-notion" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `copy_wp_to_notion:notion`)" :disabled="!!copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow)" :title="copyWordPressToNotionReason({!! $linkExpr !!}, fieldRow) || `Copy the WordPress value into Notion`" @click.stop="copySingleFieldFromWordPress({!! $linkExpr !!}, fieldRow)" data-sfpf-action="copy-wp-to-notion">Copy WordPress to Notion</button>
                                                        <button type="button" class="jd-sfpf-fbtn jd-sfpf-mini-ai" :class="fieldButtonClass({!! $linkExpr !!}, fieldRow, `ai_propose:field`)" :disabled="bridgeCardBusy({!! $linkExpr !!}) || !aiOptions.length" :title="bridgeCardBusy({!! $linkExpr !!}) ? disabledTitle({!! $linkExpr !!}) : (!aiOptions.length ? `AI proposals are inactive because no AI model is configured.` : `Ask AI to propose a value for this field`)" @click.stop="proposeAi({!! $linkExpr !!}, fieldRow.key)" data-sfpf-action="ai-propose-field">Propose with AI</button>
                                                        <span x-show="fieldRow.save_status === `error`" class="jd-sfpf-status-error" x-text="fieldRow.save_message"></span>
                                                    </div>
                                                    <div class="jd-control-reason" x-show="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)" x-text="fieldPushReason({!! $linkExpr !!}, fieldRow, `notion_to_wp`) || fieldPushReason({!! $linkExpr !!}, fieldRow, `wp_to_notion`)"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>