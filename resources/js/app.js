import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { refreshSession, session } from './lib/session';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'home', component: () => import('./views/HomeView.vue') },
        { path: '/play', name: 'play', component: () => import('./views/PlayView.vue'), meta: { auth: true } },
        { path: '/match/:id', name: 'match', component: () => import('./views/MatchView.vue'), meta: { auth: true }, props: true },
        { path: '/practice', name: 'practice', component: () => import('./views/PracticeView.vue') },
        { path: '/leaderboard', name: 'leaderboard', component: () => import('./views/LeaderboardView.vue') },
        { path: '/players/:name', name: 'player', component: () => import('./views/ProfileView.vue'), props: true },
        { path: '/replay/:id', name: 'replay', component: () => import('./views/ReplayView.vue'), props: true },
        { path: '/watch/:id', name: 'watch', component: () => import('./views/WatchView.vue'), props: true },
        { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach(async (to) => {
    if (!session.ready) await refreshSession();
    if (to.meta.auth && !session.user) return { name: 'home', query: { next: to.fullPath } };
});

createApp(App).use(router).mount('#app');
