<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSelect
		class="openai-multiselect"
		:model-value="value"
		:multiple="true"
		:loading="loadingSuggestions"
		:options="formattedSuggestions"
		:placeholder="placeholder"
		:clear-search-on-select="true"
		:close-on-select="true"
		:clearable="true"
		:user-select="false"
		:filterable="false"
		:append-to-body="false"
		v-bind="$attrs"
		@search="asyncFind"
		@update:model-value="$emit('update:value', $event)">
		<template #option="option">
			<div class="select-suggestion">
				<NcAvatar
					v-if="option.entity_type === ENTITY_TYPES.user"
					:user="option.entity_id"
					:hide-status="true" />
				<NcAvatar
					v-else-if="option.entity_type === ENTITY_TYPES.group"
					:display-name="option.display_name"
					:is-no-user="true"
					:disable-tooltip="true"
					:hide-status="true" />
				<span class="multiselect-name">
					{{ option.display_name }}
				</span>
				<span
					:class="{
						icon: true,
						[typeIconClass[option.entity_type]]: true,
						'multiselect-icon': true,
					}" />
			</div>
		</template>
		<template #selected-option="option">
			<NcAvatar
				v-if="option.entity_type === ENTITY_TYPES.user"
				:user="option.entity_id"
				:hide-status="true" />
			<NcAvatar
				v-else-if="option.entity_type === ENTITY_TYPES.group"
				:display-name="option.display_name"
				:is-no-user="true"
				:disable-tooltip="true"
				:hide-status="true" />
			<span class="multiselect-name">
				{{ option.display_name }}
			</span>
			<span
				:class="{
					icon: true,
					[typeIconClass[option.entity_type]]: true,
					'multiselect-icon': true,
				}" />
		</template>
		<template #noOptions>
			{{ t("integration_openai", "No recommendations. Start typing.") }}
		</template>
		<template #noResult>
			{{ t("integration_openai", "No result.") }}
		</template>
	</NcSelect>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const typeIconClass = ['icon-user', 'icon-group']
const ENTITY_TYPES = {
	user: 0,
	group: 1,
}

export default {
	name: 'MultiselectWho',

	components: {
		NcAvatar,
		NcSelect,
	},

	props: {
		value: {
			type: Array,
			required: true,
		},
		types: {
			type: Array,
			default: () => [0, 1],
		},
		placeholder: {
			type: String,
			default: t('integration_openai', 'Who?'),
		},
	},

	emits: ['update:value'],

	data() {
		return {
			typeIconClass,
			loadingSuggestions: false,
			suggestions: [],
			query: '',
			currentUser: getCurrentUser(),
			ENTITY_TYPES,
		}
	},

	computed: {
		formattedSuggestions() {
			// users suggestions (avoid selected users)
			const result = this.suggestions
				.filter((s) => {
					return (
						s.source === 'users'
								&& !this.value.find((u) => u.entity_type === ENTITY_TYPES.user && u.entity_id === s.id)
					)
				})
				.map((s) => {
					return {
						entity_id: s.id,
						entity_type: ENTITY_TYPES.user,
						display_name: s.label,
						id: ENTITY_TYPES.user + '-' + s.id,
					}
				})

			// add current user (who is absent from autocomplete suggestions)
			// if it matches the query
			if (this.currentUser && this.query) {
				const lowerCurrent = this.currentUser.displayName.toLowerCase()
				const lowerQuery = this.query.toLowerCase()
				// don't add it if it's selected
				if (
					lowerCurrent.match(lowerQuery)
						&& !this.value.find(
							(u) => u.entity_type === ENTITY_TYPES.user && u.entity_id === this.currentUser.uid,
						)
				) {
					result.push({
						entity_id: this.currentUser.uid,
						entity_type: ENTITY_TYPES.user,
						display_name: this.currentUser.displayName,
						id: ENTITY_TYPES.user + '-' + this.currentUser.uid,
					})
				}
			}
			console.debug(result)

			// groups suggestions (avoid selected ones)
			const groups = this.suggestions
				.filter((s) => {
					return (
						s.source === 'groups'
								&& !this.value.find((u) => u.entity_type === ENTITY_TYPES.group && u.entity_id === s.id)
					)
				})
				.map((s) => {
					return {
						entity_id: s.id,
						entity_type: ENTITY_TYPES.group,
						display_name: s.label,
						id: ENTITY_TYPES.group + '-' + s.id,
					}
				})
			result.push(...groups)

			return result
		},
	},

	watch: {},

	mounted() {},

	methods: {
		asyncFind(query) {
			this.query = query
			if (query === '') {
				this.suggestions = []
				return
			}
			this.loadingSuggestions = true
			const url = generateOcsUrl('core/autocomplete/get', 2).replace(/\/$/, '')
			axios
				.get(url, {
					params: {
						format: 'json',
						search: query,
						itemType: ' ',
						itemId: ' ',
						shareTypes: this.types,
					},
				})
				.then((response) => {
					this.suggestions = response.data.ocs.data
				})
				.catch((error) => {
					showError(t('integration_openai', 'Impossible to get user/group list'))
					console.error(error)
				})
				.finally(() => {
					this.loadingSuggestions = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.openai-multiselect {
	.multiselect-name {
		flex-grow: 1;
		margin-left: 10px;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.multiselect-icon {
		opacity: 0.5;
		margin-left: 4px;
	}
	.select-suggestion {
		display: flex;
		align-items: center;
	}
}
</style>
