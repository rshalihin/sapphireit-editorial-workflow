/**
 * Jest setup: a default `window.sitCwm` bootstrap object, mirroring the shape
 * `Sit_Cwm\Core\Assets::bootstrap_data()` prints. Tests override it as needed.
 */
beforeEach( () => {
	window.sitCwm = {
		restNamespace: 'sit-cwm/v1',
		statuses: [],
		capabilities: {},
		postTypes: [],
		adminUrl: 'http://example.org/wp-admin/',
	};
} );

afterEach( () => {
	delete window.sitCwm;
} );
