/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('weareplanet-order-action-refund', {
	template,

	inject: ['WeArePlanetRefundService'],

	mixins: [
		Mixin.getByName('notification')
	],

	props: {
		transactionData: {
			type: Object,
			required: true
		},

		orderId: {
			type: String,
			required: true
		}
	},

	data() {
		return {
			refundQuantity: 0,
			isLoading: true,
			currentLineItem: '',
		};
	},

	computed: {
		dateFilter() {
			return Filter.getByName('date');
		}
	},

	created() {
		this.createdComponent();
	},

	methods: {
		createdComponent() {
			this.isLoading = false;
			this.refundQuantity = 1;
		},

		refund() {
			this.isLoading = true;
			this.WeArePlanetRefundService.createRefund(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				this.refundQuantity,
				this.$parent.$parent.currentLineItem
			).then(() => {
				this.createNotificationSuccess({
					title: this.$tc('weareplanet-order.refundAction.successTitle'),
					message: this.$tc('weareplanet-order.refundAction.successMessage')
				});
				this.isLoading = false;
				this.$emit('modal-close');
				this.$nextTick(() => {
					this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
				});
			}).catch((errorResponse) => {
				try {
					this.createNotificationError({
						title: errorResponse.response.data.errors[0].title,
						message: errorResponse.response.data.errors[0].detail,
						autoClose: false
					});
				} catch (e) {
					this.createNotificationError({
						title: errorResponse.title,
						message: errorResponse.message,
						autoClose: false
					});
				} finally {
					this.isLoading = false;
					this.$emit('modal-close');
					this.$nextTick(() => {
						this.$router.replace(`${this.$route.path}?hash=${Utils.createId()}`);
					});
				}
			});
		}
	}
});
