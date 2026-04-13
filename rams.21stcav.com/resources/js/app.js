import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

import '../../node_modules/frappe-gantt/dist/frappe-gantt.css';
import Gantt from 'frappe-gantt';
window.Gantt = Gantt;
