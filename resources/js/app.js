import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';
// Auto-initialises every [data-simplebar] element, including ones Livewire adds later.
import 'simplebar';
import 'simplebar/dist/simplebar.min.css';
import { boot } from './layout';
import { bootUi } from './ui';
import { bootCharts } from './charts';

// Livewire ships Alpine; Bootstrap and SweetAlert2 are exposed for Blade/Alpine snippets.
window.bootstrap = bootstrap;
window.Swal = Swal;

boot();
bootUi();
bootCharts();
