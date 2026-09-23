import { createRoot } from '@wordpress/element';
import App from './App';
import './admin.scss';

const root = document.getElementById( 'devbridge-admin-root' );
if ( root && window.devbridgeAdmin ) {
	createRoot( root ).render( <App /> );
}
