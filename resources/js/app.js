import './bootstrap';
import Alpine from 'alpinejs';
import { createApp } from 'vue';
import DrawingCanvas from './components/DrawingCanvas.vue';

// Initialize Alpine.js
window.Alpine = Alpine;
Alpine.start();

// Initialize Vue app
const app = createApp(DrawingCanvas);

// Mount the app
app.mount('#drawing-app');
