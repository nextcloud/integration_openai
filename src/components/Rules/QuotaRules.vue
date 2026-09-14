<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcNoteCard type="info">
			{{ t('integration_openai', 'Rules can be set for specific groups or users. Each rule applies to a single service and overrides the quota configured on that service.') }}
			{{ t('integration_openai', 'Only the rule with the highest priority is active if multiple rules of the same service match.') }}
		</NcNoteCard>
		<NcNoteCard v-if="services.length === 0" type="warning">
			{{ t('integration_openai', 'Connect a service to set quota rules for it.') }}
		</NcNoteCard>
		<Rule v-for="(rule, index) in state"
			:key="index"
			v-model:rule-data="state[index]"
			:quota-info="quotaInfo ?? []"
			:services="services"
			@delete="removeRule(index)" />
		<NcButton :disabled="services.length === 0" @click="addRule">
			{{ t('integration_openai', 'Add Quota rule') }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Rule from './Rule.vue'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'QuotaRules',

	components: {
		NcButton,
		NcNoteCard,
		Rule,
	},

	props: {
		quotaInfo: {
			type: Object,
			required: true,
		},
		services: {
			type: Array,
			required: true,
		},
	},

	data() {
		return {
			state: loadState('integration_openai', 'rules', []),
		}
	},

	computed: {
	},

	watch: {
		// the backend deletes the rules of a service along with the service,
		// so a removed service takes its rules out of the list as well
		services(services) {
			const ids = services.map((service) => service.id)
			this.state = this.state.filter((rule) => ids.includes(rule.service_id))
		},
	},

	mounted() {
	},

	methods: {
		addRule() {
			return axios.post(generateUrl('/apps/integration_openai/quota/rule')).then((response) => {
				const data = response.data ?? {}
				console.debug(data)
				this.state.push(data)
				showSuccess(t('integration_openai', 'Quota rule created'))
			}).catch((error) => {
				showError(
					t('integration_openai', 'Failed to add quota rule')
					+ ': ' + error.response?.data?.error,
					{ timeout: 10000 },
				)
				console.error(error)
			})
		},
		removeRule(index) {
			return axios.delete(generateUrl('/apps/integration_openai/quota/rule'), {
				params: {
					id: this.state[index].id,
				},
			}).then(() => {
				this.state.splice(index, 1)
				showSuccess(t('integration_openai', 'Quota rule deleted'))
			}).catch((error) => {
				showError(
					t('integration_openai', 'Failed to delete quota rule')
					+ ': ' + error.response?.data?.error,
					{ timeout: 10000 },
				)
				console.error(error)
			})
		},
	},
}
</script>

<style scoped lang="scss">

</style>
