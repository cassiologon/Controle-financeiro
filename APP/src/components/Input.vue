<template>
  <div class="input-group">
    <!-- Static Label -->
    <label
      v-if="!floatingLabel && label"
      :for="id"
      class="block text-sm font-semibold text-gray-700 mb-2"
    >
      {{ label }}
      <span v-if="required" class="text-error-500">*</span>
    </label>

    <div class="relative">
      <!-- Icon Left -->
      <div
        v-if="iconLeft"
        class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none z-10"
      >
        <component :is="iconLeft" class="w-5 h-5" />
      </div>

      <!-- Input Field -->
      <input
        :id="id"
        :type="inputType"
        :value="modelValue"
        :placeholder="floatingLabel ? ' ' : placeholder"
        :disabled="disabled"
        :required="required"
        :class="[
          'input w-full',
          floatingLabel ? 'peer' : '',
          iconLeft ? 'pl-12' : 'pl-4',
          iconRight || showPasswordToggle ? 'pr-12' : 'pr-4',
          error ? 'input-error border-error-500' : '',
          success ? 'input-success border-success-500' : '',
          disabled ? 'opacity-50 cursor-not-allowed bg-gray-50' : '',
        ]"
        @input="handleInput($event)"
        @focus="handleFocus"
        @blur="handleBlur"
      />

      <!-- Icon Right / Password Toggle -->
      <div
        v-if="iconRight || (type === 'password' && showPasswordToggle)"
        class="absolute right-4 top-1/2 -translate-y-1/2 z-10"
      >
        <button
          v-if="type === 'password' && showPasswordToggle"
          type="button"
          @click="togglePassword"
          class="text-gray-400 hover:text-gray-600 transition-colors"
        >
          <svg
            v-if="showPassword"
            class="w-5 h-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
            />
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
            />
          </svg>
          <svg
            v-else
            class="w-5 h-5"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
            />
          </svg>
        </button>
        <component v-else-if="iconRight" :is="iconRight" class="w-5 h-5 text-gray-400" />
      </div>

      <!-- Floating Label -->
      <label
        v-if="floatingLabel && label"
        :for="id"
        class="label-floating"
      >
        {{ label }}
        <span v-if="required" class="text-error-500">*</span>
      </label>
    </div>

    <!-- Error Message -->
    <transition
      enter-active-class="transition-all duration-300"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-200"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div v-if="error" class="mt-2 flex items-center gap-2 text-sm text-error-600">
        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
          <path
            fill-rule="evenodd"
            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
            clip-rule="evenodd"
          />
        </svg>
        <span>{{ error }}</span>
      </div>
    </transition>

    <!-- Success Message -->
    <transition
      enter-active-class="transition-all duration-300"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-200"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div v-if="success && !error" class="mt-2 flex items-center gap-2 text-sm text-success-600">
        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
          <path
            fill-rule="evenodd"
            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
            clip-rule="evenodd"
          />
        </svg>
        <span>{{ success }}</span>
      </div>
    </transition>

    <!-- Hint -->
    <p v-if="hint && !error && !success" class="mt-2 text-sm text-gray-500">
      {{ hint }}
    </p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  id: String,
  label: String,
  type: String,
  modelValue: {
    type: [String, Number],
    required: true
  },
  placeholder: String,
  disabled: Boolean,
  required: Boolean,
  error: String,
  success: String,
  hint: String,
  iconLeft: null,
  iconRight: null,
  floatingLabel: Boolean,
  showPasswordToggle: Boolean
})

const emit = defineEmits(['update:modelValue', 'blur', 'focus'])

const showPassword = ref(false)
const inputType = computed(() => {
  if (props.type === 'password' && props.showPasswordToggle) {
    return showPassword.value ? 'text' : 'password'
  }
  return props.type || 'text'
})

function handleInput(event) {
  const target = event.target
  const value = props.type === 'number'
    ? (target.value ? parseFloat(target.value) : 0)
    : target.value
  emit('update:modelValue', value)
}

function handleFocus(event) {
  emit('focus', event)
}

function handleBlur(event) {
  emit('blur', event)
}

function togglePassword() {
  showPassword.value = !showPassword.value
}
</script>
