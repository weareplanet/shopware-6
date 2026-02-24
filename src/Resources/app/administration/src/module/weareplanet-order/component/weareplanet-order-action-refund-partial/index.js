/* global Shopware */

import template from './index.html.twig';

const {Component, Mixin, Filter, Utils} = Shopware;

Component.register('weareplanet-order-action-refund-partial', {
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
			isLoading: true,
			currency: this.transactionData.transactions[0].currency,
			refundAmount: 0.00,
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
            this.currency = this.transactionData.transactions[0].currency;
            if (!this.refundAmount) {
                this.refundAmount = this.$parent.$parent.itemRefundableAmount;
            }
        },

		createPartialRefund(itemUniqueId) {
			this.isLoading = true;
			this.WeArePlanetRefundService.createPartialRefund(
				this.transactionData.transactions[0].metaData.salesChannelId,
				this.transactionData.transactions[0].id,
				this.refundAmount,
				itemUniqueId
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
					var errorTitle = errorResponse?.response?.data?.errors?.[0]?.title ?? this.$tc('weareplanet-order.refundAction.refundCreateError.errorTitle')
					var errorMessage;
					switch(errorResponse.response.data) {
						case 'methodDoesNotSupportRefund':
							errorMessage = this.$tc('weareplanet-order.refundAction.refundCreateError.messagePaymentMethodDoesNotSupportRefund');
						break;
						default:
							errorMessage = errorResponse.response.data.errors[0].detail;
					}
					this.createNotificationError({
						title: errorTitle,
						message: errorMessage,
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
	},

    watch: {
        refundAmount(newValue) {
            if (newValue !== null) {
                this.refundAmount = Math.round(newValue * 100) / 100;
            }
        }
    }
});
