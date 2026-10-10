<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login, register } from '../lib/session';

const route = useRoute();
const router = useRouter();

const mode = ref('register');
const form = reactive({ name: '', password: '' });
const errors = ref({});
const busy = ref(false);

async function submit() {
    busy.value = true;
    errors.value = {};
    try {
        await (mode.value === 'register' ? register : login)(form.name, form.password);
        // Navigate from here: signing in unmounts this panel before an emitted event could be handled.
        router.push(route.query.next ?? { name: 'play' });
    } catch (e) {
        errors.value = e.data?.errors ?? { name: [e.message] };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900 p-6" data-testid="auth-form" @submit.prevent="submit">
        <div class="flex gap-1 rounded-lg bg-slate-800 p-1 text-sm">
            <button
                v-for="option in ['register', 'login']"
                :key="option"
                type="button"
                class="flex-1 rounded-md py-1.5 font-medium"
                :class="mode === option ? 'bg-slate-950 text-white' : 'text-slate-400'"
                @click="mode = option"
            >
                {{ option === 'register' ? 'Create account' : 'Sign in' }}
            </button>
        </div>
        <label class="block text-sm">
            <span class="text-slate-400">Username</span>
            <input
                v-model.trim="form.name"
                name="name"
                autocomplete="username"
                required
                maxlength="20"
                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
            />
            <span v-if="errors.name" class="mt-1 block text-xs text-red-400">{{ errors.name[0] }}</span>
        </label>
        <label class="block text-sm">
            <span class="text-slate-400">Password</span>
            <input
                v-model="form.password"
                name="password"
                type="password"
                :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
                required
                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
            />
            <span v-if="errors.password" class="mt-1 block text-xs text-red-400">{{ errors.password[0] }}</span>
        </label>
        <button
            type="submit"
            :disabled="busy"
            class="w-full rounded-lg bg-cyan-500 py-2 font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-50"
        >
            {{ mode === 'register' ? 'Create account & play' : 'Sign in' }}
        </button>
    </form>
</template>
