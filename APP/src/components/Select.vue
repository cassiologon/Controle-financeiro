<template>
  <div>
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>
    <select
      :id="id"
      :value="modelValue"
      :disabled="disabled"
      :required="required"
      :class="[
        'input',
        error ? 'border-red-500 focus:ring-red-500' : '',
      ]"
      @change="handleChange($event)"
    >
      <option v-if="placeholder" value="">{{ placeholder }}</option>
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
    <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup>
const props = defineProps({
  id: String,
  label: String,
  modelValue: {
    type: [String, Number],
    default: ''
  },
  options: {
    type: Array,
    required: true
  },
  placeholder: String,
  disabled: Boolean,
  required: Boolean,
  error: String
})

const emit = defineEmits(['update:modelValue'])

function handleChange(event) {
  const raw = event.target.value
  const numeric = Number(raw)
  const value = raw !== '' && !isNaN(numeric) ? numeric : raw
  emit('update:modelValue', value)
}
</script>

