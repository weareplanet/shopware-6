/* global Shopware */

import template from './index.html.twig';
import constants from './configuration-constants';

const {Component, Mixin} = Shopware;

Component.register('weareplanet-settings', {

    template: template,

    inject: [
        'acl',
        'WeArePlanetConfigurationService'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    data() {
        return {

            config: {},

            isLoading: false,
            isTesting: false,

            isSaveSuccessful: false,

            applicationKeyFilled: false,
            applicationKeyErrorState: false,

            spaceIdFilled: false,
            spaceIdErrorState: false,

            userIdFilled: false,
            userIdErrorState: false,

            isSetDefaultPaymentSuccessful: false,
            isSettingDefaultPaymentMethods: false,

            configIntegrationDefaultValue: 'payment_page',
            configEmailEnabledDefaultValue: true,
            configLineItemConsistencyEnabledDefaultValue: true,
            configStorefrontInvoiceDownloadEnabledEnabledDefaultValue: true,
            configStorefrontWebhooksUpdateEnabledDefaultValue: true,
            configStorefrontPaymentsUpdateEnabledDefaultValue: true,

            ...constants
        };
    },

    props: {
        isLoading: {
            type: Boolean,
            required: true
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    watch: {
        config: {
            handler(configData) {
                const defaultConfig = this.$refs.configComponent.allConfigs.null;
                const salesChannelId = this.$refs.configComponent.selectedSalesChannelId;
                if (salesChannelId === null) {

                    this.applicationKeyFilled = !!this.config[this.CONFIG_APPLICATION_KEY];
                    this.spaceIdFilled = !!this.config[this.CONFIG_SPACE_ID];
                    this.userIdFilled = !!this.config[this.CONFIG_USER_ID];

                    if (!(this.CONFIG_INTEGRATION in this.config)) {
                        this.config[this.CONFIG_INTEGRATION] = this.configIntegrationDefaultValue;
                    }

                    if (!(this.CONFIG_EMAIL_ENABLED in this.config)) {
                        this.config[this.CONFIG_EMAIL_ENABLED] = this.configEmailEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config)) {
                        this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED] = this.configLineItemConsistencyEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED] = this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] = this.configStorefrontWebhooksUpdateEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config)) {
                        this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] = this.configStorefrontPaymentsUpdateEnabledDefaultValue;
                    }

                } else {

                    this.applicationKeyFilled = !!this.config[this.CONFIG_APPLICATION_KEY] || !!defaultConfig[this.CONFIG_APPLICATION_KEY];
                    this.spaceIdFilled = !!this.config[this.CONFIG_SPACE_ID] || !!defaultConfig[this.CONFIG_SPACE_ID];
                    this.userIdFilled = !!this.config[this.CONFIG_USER_ID] || !!defaultConfig[this.CONFIG_USER_ID];


                    if (!(this.CONFIG_INTEGRATION in this.config) || !(this.CONFIG_INTEGRATION in defaultConfig)) {
                        this.config[this.CONFIG_INTEGRATION] = this.configIntegrationDefaultValue;
                    }

                    if (!(this.CONFIG_EMAIL_ENABLED in this.config) || !(this.CONFIG_EMAIL_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_EMAIL_ENABLED] = this.configEmailEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in this.config) || !(this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_LINE_ITEM_CONSISTENCY_ENABLED] = this.configLineItemConsistencyEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_INVOICE_DOWNLOAD_ENABLED] = this.configStorefrontInvoiceDownloadEnabledEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] = this.configStorefrontWebhooksUpdateEnabledDefaultValue;
                    }

                    if (!(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in this.config) || !(this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED in defaultConfig)) {
                        this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] = this.configStorefrontPaymentsUpdateEnabledDefaultValue;
                    }
                }

                this.$emit('salesChannelChanged');
                this.$emit('update:value', configData);
            },
            deep: true
        }
    },

    methods: {
        checkTextFieldInheritance(value) {
            if (typeof value !== 'string') {
                return true;
            }

            return value.length <= 0;
        },

        checkNumberFieldInheritance(value) {
            if (typeof value !== 'number') {
                return true;
            }

            return value.length <= 0;
        },

        checkBoolFieldInheritance(value) {
            return typeof value !== 'boolean';
        },

        getInheritValue(key) {
            if (this.selectedSalesChannelId == null ) {
                return this.actualConfigData[key];
            } else {
                return this.allConfigs['null'][key];
            }
        },

        onSave() {
            if (!(this.spaceIdFilled && this.userIdFilled && this.applicationKeyFilled)) {
                this.setErrorStates();
                return;
            }
            this.save();
        },

        save() {
            this.isLoading = true;

            this.$refs.configComponent.save().then((res) => {
                if (res) {
                    this.config = res;
                }
                this.registerWebHooks();
                this.synchronizePaymentMethodConfiguration();
                this.installOrderDeliveryStates();
            }).catch((e) => {
                console.error('Error:', e);
                this.isLoading = false;
            });
        },

        registerWebHooks() {
            if (this.config[this.CONFIG_STOREFRONT_WEBHOOKS_UPDATE_ENABLED] === false) {
                return false;
            }

            this.WeArePlanetConfigurationService.registerWebHooks(this.$refs.configComponent.selectedSalesChannelId)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('weareplanet-settings.settingForm.titleSuccess'),
                        message: this.$tc('weareplanet-settings.settingForm.messageWebHookUpdated')
                    });
                }).catch((e) => {
                    this.createNotificationError({
                        title: this.$tc('weareplanet-settings.settingForm.titleError'),
                        message: this.$tc('weareplanet-settings.settingForm.messageWebHookError')
                    });
                    this.isLoading = false;
                    console.error('Error:', e);
            });
        },

        synchronizePaymentMethodConfiguration() {
            if (this.config[this.CONFIG_STOREFRONT_PAYMENTS_UPDATE_ENABLED] === false) {
                return false;
            }

            this.WeArePlanetConfigurationService.synchronizePaymentMethodConfiguration(this.$refs.configComponent.selectedSalesChannelId)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('weareplanet-settings.settingForm.titleSuccess'),
                        message: this.$tc('weareplanet-settings.settingForm.messagePaymentMethodConfigurationUpdated')
                    });
                    this.isLoading = false;
                }).catch((e) => {
                    this.createNotificationError({
                        title: this.$tc('weareplanet-settings.settingForm.titleError'),
                        message: this.$tc('weareplanet-settings.settingForm.messagePaymentMethodConfigurationError')
                    });
                    this.isLoading = false;
                    console.error('Error:', e);
            });
        },

        installOrderDeliveryStates(){
            this.WeArePlanetConfigurationService.installOrderDeliveryStates()
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc('weareplanet-settings.settingForm.titleSuccess'),
                        message: this.$tc('weareplanet-settings.settingForm.messageOrderDeliveryStateUpdated')
                    });
                    this.isLoading = false;
                }).catch(() => {
                    this.createNotificationError({
                        title: this.$tc('weareplanet-settings.settingForm.titleError'),
                        message: this.$tc('weareplanet-settings.settingForm.messageOrderDeliveryStateError')
                    });
                    this.isLoading = false;
            });
        },

        onSetPaymentMethodDefault() {
            this.isSettingDefaultPaymentMethods = true;
            this.WeArePlanetConfigurationService.setWeArePlanetAsSalesChannelPaymentDefault(
                this.$refs.configComponent.selectedSalesChannelId
            ).then(() => {
                this.isSettingDefaultPaymentMethods = false;
                this.isSetDefaultPaymentSuccessful = true;
                this.createNotificationSuccess({
                    title: this.$tc('weareplanet-settings.settingForm.titleSuccess'),
                    message: this.$tc('weareplanet-settings.salesChannelCard.messageDefaultPaymentUpdated')
                });
            });
        },

        setErrorStates() {
            const messageNotBlankErrorState = {
                code: 1,
                detail: this.$tc('weareplanet-settings.messageNotBlank')
            };

            if (!this.spaceIdFilled) {
                this.spaceIdErrorState = messageNotBlankErrorState;
            }

            if (!this.userIdFilled) {
                this.userIdErrorState = messageNotBlankErrorState;
            }

            if (!this.applicationKeyFilled) {
                this.applicationKeyErrorState = messageNotBlankErrorState;
            }
        },

        // Handles the 'check-api-connection-event'.
        // Uses the provided apiConnectionData to perform API connection checks.
        onCheckApiConnection(apiConnectionData) {
            const { spaceId, userId, applicationKey } = apiConnectionData;
            this.isTesting = true;

            this.WeArePlanetConfigurationService.checkApiConnection(spaceId, userId, applicationKey)
                .then((res) => {
                    if (res.result === 200) {
                        this.createNotificationSuccess({
                            title: this.$tc('weareplanet-settings.settingForm.credentials.alert.title'),
                            message: this.$tc('weareplanet-settings.settingForm.credentials.alert.successMessage')
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc('weareplanet-settings.settingForm.credentials.alert.title'),
                            message: this.$tc('weareplanet-settings.settingForm.credentials.alert.errorMessage')
                        });
                    }
                    this.isTesting = false;
                }).catch(() => {
                    this.createNotificationError({
                        title: this.$tc('weareplanet-settings.settingForm.credentials.alert.title'),
                        message: this.$tc('weareplanet-settings.settingForm.credentials.alert.errorMessage')
                    });
                    this.isTesting = false;
            });
        }
    }
});
