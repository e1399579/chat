import Vue from 'vue'
import App from './App.vue'
import LemonIMUI from 'lemon-imui-rx';
import 'lemon-imui-rx/dist/index.css';
import lemonMessageMusic from './components/lemon-message-music.vue';
Vue.component(lemonMessageMusic.name, lemonMessageMusic);
import VModal from 'vue-js-modal';
import Notifications from 'vue-notification';
import 'viewerjs/dist/viewer.css';
import VueViewer from 'v-viewer';
import ImageCrop from 'vue-image-crop-upload/upload-2';

Vue.config.productionTip = false;
Vue.use(LemonIMUI);
Vue.use(VModal, { dialog: true });
Vue.use(Notifications);
Vue.use(VueViewer);
Vue.component('image-crop', ImageCrop);

new Vue({
  render: h => h(App),
}).$mount('#app')
