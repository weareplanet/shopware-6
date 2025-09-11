/* global Shopware */

import './acl';
import './page/weareplanet-settings';
import './component/sw-weareplanet-credentials';
import './component/sw-weareplanet-options';
import './component/sw-weareplanet-settings-icon';
import './component/sw-weareplanet-storefront-options';
import './component/sw-weareplanet-advanced-options';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import frFR from './snippet/fr-FR.json';
import itIT from './snippet/it-IT.json';

const {Module} = Shopware;

Module.register('weareplanet-settings', {
	type: 'plugin',
	name: 'WeArePlanet',
	title: 'weareplanet-settings.general.descriptionTextModule',
	description: 'weareplanet-settings.general.descriptionTextModule',
	color: '#28d8ff',
	icon: 'default-action-settings',
	version: '1.0.1',
	targetVersion: '1.0.1',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
        'fr-FR': frFR,
        'it-IT': itIT,
    },

	routes: {
		index: {
			component: 'weareplanet-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index',
				privilege: 'weareplanet.viewer'
			},
			props: {
                default: (route) => {
                    return {
                        hash: route.params.hash,
                    };
                },
            },
		}
	},

	settingsItem: {
		group: 'plugins',
		to: 'weareplanet.settings.index',
		iconComponent: 'sw-weareplanet-settings-icon',
		backgroundEnabled: true,
		privilege: 'weareplanet.viewer'
	}

});
