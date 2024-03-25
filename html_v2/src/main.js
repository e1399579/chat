import Vue from 'vue'
import App from './App.vue'
import LemonIMUI from 'lemon-imui';
import 'lemon-imui/dist/index.css';
import VModal from 'vue-js-modal';
import Notifications from 'vue-notification';

Vue.config.productionTip = false;
Vue.use(LemonIMUI);
Vue.use(VModal);
Vue.use(Notifications);

new Vue({
  render: h => h(App),
}).$mount('#app')
