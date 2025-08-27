<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="container">
		<NcNoteCard type="info">
			{{
				t('integration_openai', 'Daily quotas are floating quotas while monthly reset on a certain day of the month')
			}}
		</NcNoteCard>
		<div class="line">
			<span class="text-cell">
				{{ textBeforeInput }}
			</span>
			<NcInputField
				id="quota"
				:model-value="value.length"
				class="input"
				type="number"
				@update:model-value="update('length', $event)" />
			<NcSelect
				:model-value="unitOptionName"
				:options="unitOptions"
				@update:model-value="update('unit', $event.id)" />
		</div>
		<div v-if="value.unit==='month'" class="line">
			<span class="text-cell">
				{{ t('integration_openai', 'On day') }}
			</span>
			<NcInputField
				id="quota"
				:model-value="value?.day ?? 1"
				class="input"
				type="number"
				@update:model-value="update('day', $event)" />
		</div>
		<NcNoteCard type="success">
			{{ resetText }}
		</NcNoteCard>
	</div>
</template>

<script>

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'QuotaPeriodPicker',

	components: {
		NcNoteCard,
		NcInputField,
		NcSelect,
	},

	props: {
		value: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:value'],

	data() {
		return {
			unitOptions: [
				{ id: 'day', label: t('integration_openai', 'Days') },
				{ id: 'month', label: t('integration_openai', 'Months') },
			],
		}
	},

	computed: {
		floating() {
			return this.value.unit === 'day'
		},
		unitOptionName() {
			return this.unitOptions.find(option => option.id === this.value.unit)?.label ?? ''
		},
		resetText() {
			return this.floating
				? n('integration_openai', 'Quota will be enforced based on last %n day of usage', 'Quota will be enforced based on last %n days of usage', this.value.length)
				: n('integration_openai', 'Quota will reset for all the users every month on day number {nth_day}', 'Quota will reset for all the users every %n months on day number {nth_day}', this.value.length, { nth_day: this.value.day }) // TRANSLATORS: "nth_day" is a number like 1, 12, etc
		},
		textBeforeInput() {
			return this.floating
				? t('integration_openai', 'Quota enforcement time') // TRANSLATORS: In front of number input that allows you to select a number of days
				: t('integration_openai', 'Reset quota every') // TRANSLATORS: In front of number input that allows you to select a number of months
		},
	},

	watch: {},

	mounted() {
	},

	methods: {
		update(key, value) {
			console.debug('update', key, value)
			if (value || value === false || value === 0) {
				this.$emit('update:value', { ...this.value, [key]: value })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.line {
	display: flex;
	align-items: center;
	gap: 5px;
}

.container {
	width: 100%;
}

.input {
	width: 100px;
}
</style>
