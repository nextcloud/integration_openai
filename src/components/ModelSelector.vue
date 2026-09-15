<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="model-selector">
		<NcSelect
			:model-value="modelValue"
			:options="options"
			:input-label="label"
			:placeholder="placeholder"
			:multiple="true"
			:taggable="true"
			:close-on-select="false"
			:loading="loading"
			:create-option="option => option.trim()"
			@update:model-value="$emit('update:modelValue', $event)" />
		<p class="model-selector__hint">
			{{ hint }}
		</p>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'ModelSelector',

	components: {
		NcSelect,
	},

	props: {
		/** The selected model names */
		modelValue: {
			type: Array,
			required: true,
		},
		/** The model names the service reports, as plain strings */
		options: {
			type: Array,
			default: () => [],
		},
		label: {
			type: String,
			required: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		placeholder() {
			return this.options.length === 0
				? t('integration_openai', 'Type a model name')
				: t('integration_openai', 'Select or type a model name')
		},
		hint() {
			return t('integration_openai', 'Each selected model is exposed as a provider. Models that the service does not list can be typed in.')
		},
	},
}
</script>

<style scoped lang="scss">
.model-selector {
	max-width: 600px;

	&__hint {
		margin-top: 4px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
