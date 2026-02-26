<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="rule-form">
		<b>{{ t('integration_openai', 'Rule {number}', { number: ruleData.id }) }}</b>
		<div
			class="controls-row">
			<MultiselectWho
				class="user-selector"
				:value="ruleData.entities"
				:placeholder="t('integration_openai', 'Who this rule applies to?')"
				:aria-label-combobox="t('integration_openai', 'Who this rule applies to?')"
				:input-label="t('integration_openai', 'Select users/groups')"
				@update:model-value="update('entities', $event)" />
			<NcSelect
				:model-value="category"
				:options="quotaInfo.map((q) => q.type)"
				:input-label="t('integration_openai', 'Category')"
				@update:model-value="updateType($event)" />
		</div>
		<div class="controls-row">
			<NcInputField
				id="quota"
				:model-value="ruleData.amount"
				class="input"
				type="number"
				:label="ruleData.pool ? t('integration_openai', 'Shared quota') : t('integration_openai', 'Quota per user')"
				@update:model-value="update('amount', $event)" />
			<span class="text-cell">
				{{ unit }}
			</span>
			<NcInputField
				id="priority"
				:model-value="ruleData.priority"
				class="input"
				type="number"
				:label="t('integration_openai', 'Rule Priority')"
				@update:model-value="update('priority', $event)" />
			<NcFormBoxSwitch
				:model-value="ruleData.pool"
				@update:model-value="update('pool', $event)">
				{{ t('integration_openai', 'Use shared quota') }}
			</NcFormBoxSwitch>
			<NcButton v-if="needSaving"
				:disabled="saving"
				variant="success"
				@click="save">
				{{ t('integration_openai', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('delete')">
				<template #icon>
					<DeleteOutlineIcon :size="20" />
				</template>
				{{ t('integration_openai', 'Delete') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import MultiselectWho from './MultiselectWho.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'Rule',

	components: {
		NcButton,
		NcInputField,
		NcSelect,
		MultiselectWho,
		NcFormBoxSwitch,
		DeleteOutlineIcon,
	},

	props: {
		ruleData: {
			type: Object,
			required: true,
		},
		quotaInfo: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:ruleData', 'delete'],
	data() {
		return {
			needSaving: false,
			saving: false,
		}
	},

	computed: {
		unit() {
			console.debug('quotaInfo', this.quotaInfo)
			if (this.quotaInfo[this.ruleData.type] === undefined) {
				return ''
			}
			return this.quotaInfo[this.ruleData.type].unit
		},
		category() {
			if (this.quotaInfo[this.ruleData.type] === undefined) {
				return ''
			}
			return this.quotaInfo[this.ruleData.type].type
		},
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		updateType(value) {
			const idx = this.quotaInfo.findIndex((q) => q.type === value)
			if (idx === -1) {
				return
			}
			this.update('type', idx)
		},
		update(key, value) {
			console.debug('update', key, value)
			if (value || value === false || value === 0) {
				this.needSaving = true
				this.$emit('update:ruleData', { ...this.ruleData, [key]: value })
			}
		},
		save() {
			this.saving = true
			return axios.put(generateUrl('/apps/integration_openai/quota/rule'), {
				rule: this.ruleData,
				id: this.ruleData.id,
			}).then((response) => {
				this.needSaving = false
				const data = response.data ?? {}
				this.$emit('update:ruleData', data)
				showSuccess(t('integration_openai', 'Quota rule saved'))
			}).catch((error) => {
				showError(
					t('integration_openai', 'Failed to add quota rule')
						+ ': ' + error.response?.data?.error,
					{ timeout: 10000 },
				)
				console.error(error)
			}).finally(() => { this.saving = false })
		},
	},
}
</script>

<style scoped lang="scss">

.controls-row {
	gap: 10px;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
}

.input {
	width: 120px;
}
.rule-form {
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	padding: 10px;
	margin: 10px 0;
}
.user-selector {
	max-width: 600px;
}
</style>
