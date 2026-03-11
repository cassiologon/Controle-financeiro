<template>
  <div
    ref="pickerRef"
    @click.stop
    class="emoji-picker bg-white rounded-xl shadow-xl border border-gray-200 p-4 w-80 max-h-96 flex flex-col"
    style="overflow: hidden;"
  >
    <!-- Search Bar -->
    <div class="mb-3">
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Buscar emoji..."
        @click.stop
        @keydown.stop
        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
      />
    </div>

    <!-- Categories Tabs -->
    <div class="flex gap-1 mb-3 overflow-x-auto pb-2 scrollbar-hide" style="min-height: 2.5rem; overflow-y: visible;">
      <button
        v-for="category in categories"
        :key="category.id"
        type="button"
        @click.stop="selectedCategory = category.id"
        :class="[
          'px-3 py-1.5 text-xs font-medium rounded-lg whitespace-nowrap transition-colors flex-shrink-0',
          selectedCategory === category.id
            ? 'bg-indigo-100 text-indigo-700'
            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        ]"
      >
        {{ category.name }}
      </button>
    </div>

    <!-- Emojis Grid -->
    <div class="flex-1 overflow-y-auto">
      <div v-if="filteredEmojis.length > 0" class="grid grid-cols-8 gap-2">
        <button
          v-for="(emoji, index) in filteredEmojis"
          :key="index"
          type="button"
          @click.stop="selectEmoji(emoji)"
          :class="[
            'w-10 h-10 rounded-lg flex items-center justify-center text-xl hover:bg-gray-100 transition-colors cursor-pointer',
            modelValue === emoji ? 'bg-indigo-100 ring-2 ring-indigo-500' : ''
          ]"
          :title="emoji"
        >
          {{ emoji }}
        </button>
      </div>
      <div v-else class="text-center py-8 text-gray-500 text-sm">
        Nenhum emoji encontrado
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:modelValue', 'close'])

const pickerRef = ref(null)
const searchQuery = ref('')
const selectedCategory = ref('all')

// Helper function to remove duplicates from array
function uniqueEmojis(emojis) {
  return [...new Set(emojis)]
}

const categories = [
  { id: 'all', name: 'Todos', emojis: [] },
  { id: 'food', name: 'Comida', emojis: uniqueEmojis(['🍔', '🍕', '🍟', '🌮', '🌯', '🥗', '🍝', '🍜', '🍱', '🍣', '🍛', '🍙', '🍘', '🍚', '🍞', '🥐', '🥖', '🥨', '🥯', '🥞', '🧇', '🍳', '🥓', '🥩', '🍗', '🍖', '🌭', '🍿', '🧂', '🥫', '🍲', '🍥', '🥟', '🥠', '🥡', '🍢', '🍡', '🍧', '🍨', '🍦', '🥧', '🍰', '🎂', '🍮', '🍭', '🍬', '🍫', '🍩', '🍪', '🌰', '🥜', '🍯', '🥛', '🍼', '☕', '🍵', '🧃', '🥤', '🍶', '🍺', '🍻', '🥂', '🍷', '🥃', '🍸', '🍹', '🧉', '🍾', '🧊', '🛒']) },
  { id: 'transport', name: 'Transporte', emojis: uniqueEmojis(['🚗', '🚕', '🚙', '🚌', '🚎', '🏎️', '🚓', '🚑', '🚒', '🚐', '🛻', '🚚', '🚛', '🚜', '🏍️', '🛵', '🚲', '🛴', '🛹', '🛼', '🚁', '✈️', '🛩️', '🛫', '🛬', '🪂', '💺', '🚢', '⛵', '🚤', '🛥️', '🛳️', '⛴️', '🚂', '🚃', '🚄', '🚅', '🚆', '🚇', '🚈', '🚉', '🚊', '🚝', '🚞', '🚋', '🚍', '🚔', '🚖', '🚘', '🦽', '🦼']) },
  { id: 'housing', name: 'Habitação', emojis: uniqueEmojis(['🏠', '🏡', '🏘️', '🏚️', '🏗️', '🏭', '🏢', '🏬', '🏣', '🏤', '🏥', '🏦', '🏨', '🏪', '🏫', '🏩', '💒', '🏛️', '⛪', '🕌', '🕍', '🕋', '⛩️', '🛤️', '🛣️', '🗾', '🎑', '🏞️', '🌅', '🌄', '🌠', '🎇', '🎆', '🌇', '🌆', '🏙️', '🌃', '🌌', '🌉', '🌁']) },
  { id: 'health', name: 'Saúde', emojis: uniqueEmojis(['🏥', '⚕️', '🩺', '💊', '💉', '🩸', '🩹', '🩼', '🩻', '🧬', '🔬', '🏋️', '🤸', '🧘', '🧘‍♂️', '🧘‍♀️', '🏃', '🏃‍♂️', '🏃‍♀️', '🚶', '🚶‍♂️', '🚶‍♀️', '🧍', '🧍‍♂️', '🧍‍♀️', '🧎', '🧎‍♂️', '🧎‍♀️']) },
  { id: 'education', name: 'Educação', emojis: uniqueEmojis(['📚', '📖', '📗', '📘', '📙', '📕', '📓', '📔', '📒', '📃', '📜', '📄', '📑', '🧾', '📊', '📈', '📉', '🗒️', '🗓️', '📆', '📅', '📇', '🗃️', '🗳️', '🗄️', '📋', '📁', '📂', '🗂️', '🗞️', '📰', '🔖', '🧷', '🔗', '📎', '🖇️', '📐', '📏', '🧮', '📌', '📍', '✂️', '🖊️', '🖋️', '✒️', '🖌️', '🖍️', '📝', '✏️', '🔍', '🔎', '🔏', '🔐', '🔒', '🔓', '🎓']) },
  { id: 'leisure', name: 'Lazer', emojis: uniqueEmojis(['🎬', '🎮', '🎯', '🎲', '🧩', '♟️', '🎨', '🖼️', '🎭', '🎪', '🎤', '🎧', '🎼', '🎵', '🎶', '🎹', '🥁', '🎷', '🎺', '🎸', '🪗', '🎻', '🎳', '🎰', '🎪', '🎡', '🎢', '🎠']) },
  { id: 'clothing', name: 'Vestuário', emojis: uniqueEmojis(['👕', '👔', '👖', '🧣', '🧤', '🧥', '🧦', '👗', '👘', '🥻', '🩱', '🩲', '🩳', '👙', '👚', '👛', '👜', '👝', '🛍️', '🎒', '👞', '👟', '🥾', '🥿', '👠', '👡', '🩰', '👢', '👑', '👒', '🎩', '🎓', '🧢', '⛑️', '🪖', '💄', '💍', '💎']) },
  { id: 'finance', name: 'Financeiro', emojis: uniqueEmojis(['💰', '💴', '💵', '💶', '💷', '💸', '💳', '🧾', '💹', '💱', '💲', '📊', '📈', '📉', '💼', '🏦']) },
  { id: 'other', name: 'Outros', emojis: uniqueEmojis(['📦', '📫', '📪', '📬', '📭', '📮', '🗳️', '✉️', '📧', '📨', '📩', '📤', '📥', '⚡', '🔥', '💧', '🌊', '🔧', '🔨', '⚒️', '🛠️', '⛏️', '🔩', '⚙️', '🧰', '🧲', '🪓', '💈', '⚗️', '🔭', '🕳️', '🧹', '🪠', '🧺', '🧻', '🚽', '🚿', '🛁', '🛀', '🧼', '🪒', '🧽', '🪣', '🧴', '🛎️', '🔑', '🗝️', '🚪', '🪑', '🛋️', '🛏️', '🛌', '🧸', '🪆', '🪅', '🪇', '🪈', '🪐', '🌍', '🌎', '🌏', '🌐', '🗺️', '🧭', '🏔️', '⛰️', '🌋', '🗻', '🏕️', '🏖️', '🏜️', '🏝️', '🏞️', '🏟️', '🏛️', '🏗️', '🧱', '🪨', '🪵', '🛖', '⛲', '⛺', '♨️']) }
]

// Combine all emojis for "all" category (remove duplicates)
const allEmojis = uniqueEmojis(categories.slice(1).flatMap(cat => cat.emojis))
categories[0].emojis = allEmojis

// Emoji keywords mapping for search
const emojiKeywords = {
  // Food
  '🍔': ['hamburguer', 'burger', 'comida', 'lanche', 'fast food'],
  '🍕': ['pizza', 'comida', 'lanche'],
  '🍟': ['batata', 'frita', 'comida', 'lanche'],
  '🌮': ['taco', 'comida', 'mexicano'],
  '🌯': ['burrito', 'comida', 'mexicano'],
  '🥗': ['salada', 'comida', 'saudavel', 'verde'],
  '🍝': ['macarrao', 'espaguete', 'comida', 'italiano'],
  '🍜': ['ramen', 'comida', 'sopa'],
  '🍱': ['bento', 'comida', 'japones'],
  '🍣': ['sushi', 'comida', 'japones'],
  '🍛': ['curry', 'comida', 'arroz'],
  '🍙': ['onigiri', 'comida', 'japones'],
  '🍘': ['bolinho', 'arroz', 'comida'],
  '🍚': ['arroz', 'comida'],
  '🍞': ['pao', 'comida'],
  '🥐': ['croissant', 'pao', 'comida'],
  '🥖': ['baguette', 'pao', 'comida'],
  '🥨': ['pretzel', 'comida'],
  '🥯': ['bagel', 'pao', 'comida'],
  '🥞': ['panqueca', 'comida', 'cafe'],
  '🧇': ['waffle', 'comida', 'cafe'],
  '🍳': ['ovo', 'frito', 'comida'],
  '🥓': ['bacon', 'comida'],
  '🥩': ['carne', 'comida'],
  '🍗': ['frango', 'comida'],
  '🍖': ['carne', 'osso', 'comida'],
  '🌭': ['cachorro', 'quente', 'hot dog', 'comida'],
  '🍿': ['pipoca', 'comida', 'cinema'],
  '🧂': ['sal', 'tempero'],
  '🥫': ['lata', 'conserva', 'comida'],
  '🍲': ['panela', 'comida', 'sopa'],
  '🍥': ['bolinho', 'peixe', 'comida'],
  '🥟': ['dumpling', 'comida'],
  '🥠': ['biscoito', 'sorte', 'comida'],
  '🥡': ['marmita', 'comida', 'chines'],
  '🍢': ['espetinho', 'comida'],
  '🍡': ['dango', 'comida', 'japones'],
  '🍧': ['raspadinha', 'gelo', 'doce'],
  '🍨': ['sorvete', 'gelado', 'doce'],
  '🍦': ['sorvete', 'casquinha', 'doce'],
  '🥧': ['torta', 'doce', 'comida'],
  '🍰': ['bolo', 'doce', 'aniversario'],
  '🎂': ['bolo', 'aniversario', 'doce'],
  '🍮': ['pudim', 'doce'],
  '🍭': ['pirulito', 'doce'],
  '🍬': ['doce', 'balinha'],
  '🍫': ['chocolate', 'doce'],
  '🍩': ['donut', 'rosquinha', 'doce'],
  '🍪': ['biscoito', 'cookie', 'doce'],
  '🌰': ['castanha', 'noz'],
  '🥜': ['amendoim', 'noz'],
  '🍯': ['mel', 'doce'],
  '🥛': ['leite', 'bebida'],
  '🍼': ['mamadeira', 'bebe'],
  '☕': ['cafe', 'bebida'],
  '🍵': ['cha', 'bebida'],
  '🧃': ['suco', 'bebida'],
  '🥤': ['refrigerante', 'bebida'],
  '🍶': ['sake', 'bebida', 'japones'],
  '🍺': ['cerveja', 'bebida'],
  '🍻': ['cerveja', 'brinde', 'bebida'],
  '🥂': ['champagne', 'brinde', 'bebida'],
  '🍷': ['vinho', 'bebida'],
  '🥃': ['whisky', 'bebida'],
  '🍸': ['coquetel', 'bebida'],
  '🍹': ['bebida', 'tropical'],
  '🧉': ['mate', 'bebida'],
  '🍾': ['champagne', 'garrafa', 'bebida'],
  '🧊': ['gelo', 'cubo'],
  '🛒': ['carrinho', 'compras', 'supermercado', 'mercado', 'comida'],
  // Transport
  '🚗': ['carro', 'automovel', 'transporte'],
  '🚕': ['taxi', 'carro', 'transporte'],
  '🚙': ['suv', 'carro', 'transporte'],
  '🚌': ['onibus', 'transporte'],
  '🚎': ['trolebus', 'transporte'],
  '🏎️': ['formula', 'carro', 'corrida'],
  '🚓': ['policia', 'carro', 'transporte'],
  '🚑': ['ambulancia', 'saude', 'transporte'],
  '🚒': ['bombeiro', 'carro', 'transporte'],
  '🚐': ['van', 'transporte'],
  '🛻': ['pickup', 'caminhonete', 'transporte'],
  '🚚': ['caminhao', 'transporte'],
  '🚛': ['caminhao', 'grande', 'transporte'],
  '🚜': ['trator', 'transporte'],
  '🏍️': ['moto', 'motocicleta', 'transporte'],
  '🛵': ['moto', 'scooter', 'transporte'],
  '🚲': ['bicicleta', 'bike', 'transporte'],
  '🛴': ['patinete', 'transporte'],
  '🛹': ['skate', 'transporte'],
  '🛼': ['patins', 'transporte'],
  '🚁': ['helicoptero', 'transporte'],
  '✈️': ['aviao', 'transporte'],
  '🛩️': ['aviao', 'pequeno', 'transporte'],
  '🛫': ['decolagem', 'aviao'],
  '🛬': ['pouso', 'aviao'],
  '🪂': ['paraquedas'],
  '💺': ['assento', 'cadeira'],
  '🚢': ['navio', 'barco', 'transporte'],
  '⛵': ['veleiro', 'barco', 'transporte'],
  '🚤': ['lancha', 'barco', 'transporte'],
  '🛥️': ['iate', 'barco', 'transporte'],
  '🛳️': ['cruzeiro', 'navio', 'transporte'],
  '⛴️': ['ferry', 'barco', 'transporte'],
  '🚂': ['trem', 'locomotiva', 'transporte'],
  '🚃': ['trem', 'vagao', 'transporte'],
  '🚄': ['trem', 'rapido', 'transporte'],
  '🚅': ['trem', 'alta velocidade', 'transporte'],
  '🚆': ['trem', 'transporte'],
  '🚇': ['metro', 'subway', 'transporte'],
  '🚈': ['monotrilho', 'transporte'],
  '🚉': ['estacao', 'trem'],
  '🚊': ['bonde', 'transporte'],
  '🚝': ['trem', 'transporte'],
  '🚞': ['trem', 'montanha', 'transporte'],
  '🚋': ['trem', 'vagao', 'transporte'],
  '🚍': ['onibus', 'transporte'],
  '🚔': ['policia', 'carro', 'transporte'],
  '🚖': ['taxi', 'transporte'],
  '🚘': ['carro', 'transporte'],
  '🦽': ['cadeira', 'rodas'],
  '🦼': ['scooter', 'mobilidade'],
  // Housing
  '🏠': ['casa', 'moradia', 'habitacao'],
  '🏡': ['casa', 'jardim', 'habitacao'],
  '🏘️': ['bairro', 'casas', 'habitacao'],
  '🏚️': ['casa', 'velha', 'habitacao'],
  '🏗️': ['construcao', 'obra'],
  '🏭': ['fabrica', 'industria'],
  '🏢': ['predio', 'escritorio'],
  '🏬': ['shopping', 'centro comercial'],
  '🏣': ['correios', 'posto'],
  '🏤': ['correios', 'posto'],
  '🏥': ['hospital', 'saude'],
  '🏦': ['banco', 'financeiro'],
  '🏨': ['hotel', 'hospedagem'],
  '🏪': ['loja', 'comercio'],
  '🏫': ['escola', 'educacao'],
  '🏩': ['hotel', 'amor'],
  '💒': ['casamento', 'igreja'],
  '🏛️': ['templo', 'monumento'],
  '⛪': ['igreja', 'religiao'],
  '🕌': ['mesquita', 'religiao'],
  '🕍': ['sinagoga', 'religiao'],
  '🕋': ['kaaba', 'religiao'],
  '⛩️': ['santuario', 'japones'],
  '🛤️': ['trilhos', 'ferrovia'],
  '🛣️': ['estrada', 'rodovia'],
  '🗾': ['mapa', 'japao'],
  '🎑': ['lua', 'festival'],
  '🏞️': ['parque', 'nacional'],
  '🌅': ['nascer', 'sol'],
  '🌄': ['nascer', 'sol', 'montanha'],
  '🌠': ['estrela', 'cadente'],
  '🎇': ['fogos', 'artificio'],
  '🎆': ['fogos', 'artificio'],
  '🌇': ['por', 'sol'],
  '🌆': ['cidade', 'noite'],
  '🏙️': ['cidade', 'predios'],
  '🌃': ['noite', 'estrelas'],
  '🌌': ['via', 'lactea'],
  '🌉': ['ponte', 'noite'],
  '🌁': ['nevoa', 'nublado'],
  // Health
  '⚕️': ['saude', 'medico'],
  '🩺': ['estetoscopio', 'medico', 'saude'],
  '💊': ['remedio', 'medicina', 'saude'],
  '💉': ['injetavel', 'vacina', 'saude'],
  '🩸': ['sangue', 'saude'],
  '🩹': ['bandagem', 'curativo', 'saude'],
  '🩼': ['muleta', 'saude'],
  '🩻': ['raios', 'x', 'saude'],
  '🧬': ['dna', 'ciencia', 'saude'],
  '🔬': ['microscopio', 'ciencia'],
  '🏋️': ['musculacao', 'academia', 'exercicio'],
  '🤸': ['ginastica', 'exercicio'],
  '🧘': ['meditacao', 'yoga', 'exercicio'],
  '🧘‍♂️': ['meditacao', 'homem', 'yoga'],
  '🧘‍♀️': ['meditacao', 'mulher', 'yoga'],
  '🏃': ['corrida', 'exercicio'],
  '🏃‍♂️': ['corrida', 'homem', 'exercicio'],
  '🏃‍♀️': ['corrida', 'mulher', 'exercicio'],
  '🚶': ['caminhada', 'exercicio'],
  '🚶‍♂️': ['caminhada', 'homem'],
  '🚶‍♀️': ['caminhada', 'mulher'],
  '🧍': ['em pe', 'pessoa'],
  '🧍‍♂️': ['em pe', 'homem'],
  '🧍‍♀️': ['em pe', 'mulher'],
  '🧎': ['ajoelhado', 'pessoa'],
  '🧎‍♂️': ['ajoelhado', 'homem'],
  '🧎‍♀️': ['ajoelhado', 'mulher'],
  // Education
  '📚': ['livros', 'educacao', 'estudo'],
  '📖': ['livro', 'aberto', 'educacao'],
  '📗': ['livro', 'verde', 'educacao'],
  '📘': ['livro', 'azul', 'educacao'],
  '📙': ['livro', 'laranja', 'educacao'],
  '📕': ['livro', 'vermelho', 'educacao'],
  '📓': ['caderno', 'educacao'],
  '📔': ['caderno', 'decorado', 'educacao'],
  '📒': ['caderno', 'espiral', 'educacao'],
  '📃': ['folha', 'papel'],
  '📜': ['pergaminho', 'papel'],
  '📄': ['documento', 'papel'],
  '📑': ['marcador', 'pagina'],
  '🧾': ['recibo', 'nota'],
  '📊': ['grafico', 'estatistica'],
  '📈': ['grafico', 'crescimento'],
  '📉': ['grafico', 'queda'],
  '🗒️': ['bloco', 'notas'],
  '🗓️': ['calendario', 'mes'],
  '📆': ['calendario', 'parede'],
  '📅': ['calendario', 'mesa'],
  '📇': ['fichario', 'organizacao'],
  '🗃️': ['gaveta', 'arquivo'],
  '🗳️': ['urna', 'votacao'],
  '🗄️': ['arquivo', 'gaveta'],
  '📋': ['prancheta', 'lista'],
  '📁': ['pasta', 'arquivo'],
  '📂': ['pasta', 'aberta'],
  '🗂️': ['divisor', 'pasta'],
  '🗞️': ['jornal', 'noticia'],
  '📰': ['jornal', 'noticia'],
  '🔖': ['marcador', 'livro'],
  '🧷': ['alfinete', 'seguranca'],
  '🔗': ['link', 'corrente'],
  '📎': ['clipe', 'papel'],
  '🖇️': ['clipe', 'ligado'],
  '📐': ['esquadro', 'medida'],
  '📏': ['regua', 'medida'],
  '🧮': ['abaco', 'calculo'],
  '📌': ['alfinete', 'fixo'],
  '📍': ['alfinete', 'localizacao'],
  '✂️': ['tesoura', 'corte'],
  '🖊️': ['caneta', 'escrever'],
  '🖋️': ['caneta', 'tinteiro'],
  '✒️': ['caneta', 'tinteiro'],
  '🖌️': ['pincel', 'pintar'],
  '🖍️': ['giz', 'cera'],
  '📝': ['nota', 'escrever'],
  '✏️': ['lapis', 'escrever'],
  '🔍': ['lupa', 'buscar'],
  '🔎': ['lupa', 'direita'],
  '🔏': ['cadeado', 'chave'],
  '🔐': ['cadeado', 'fechado'],
  '🔒': ['cadeado', 'trancado'],
  '🔓': ['cadeado', 'aberto'],
  '🎓': ['formatura', 'educacao'],
  // Leisure
  '🎬': ['cinema', 'filme', 'lazer'],
  '🎮': ['videogame', 'jogo', 'lazer'],
  '🎯': ['alvo', 'dardo', 'jogo'],
  '🎲': ['dado', 'jogo', 'lazer'],
  '🧩': ['quebra', 'cabeca', 'puzzle'],
  '♟️': ['xadrez', 'peao', 'jogo'],
  '🎨': ['arte', 'pintura', 'lazer'],
  '🖼️': ['quadro', 'arte'],
  '🎭': ['teatro', 'mascara', 'lazer'],
  '🎪': ['circo', 'lazer'],
  '🎤': ['microfone', 'musica', 'canto'],
  '🎧': ['fone', 'ouvido', 'musica'],
  '🎼': ['partitura', 'musica'],
  '🎵': ['nota', 'musica'],
  '🎶': ['notas', 'musica'],
  '🎹': ['piano', 'teclado', 'musica'],
  '🥁': ['bateria', 'musica'],
  '🎷': ['saxofone', 'musica'],
  '🎺': ['trompete', 'musica'],
  '🎸': ['guitarra', 'musica'],
  '🪗': ['sanfona', 'musica'],
  '🎻': ['violino', 'musica'],
  '🎳': ['boliche', 'jogo', 'lazer'],
  '🎰': ['caça', 'niqueis', 'jogo'],
  '🎡': ['roda', 'gigante', 'parque'],
  '🎢': ['montanha', 'russa', 'parque'],
  '🎠': ['carrossel', 'parque'],
  // Clothing
  '👕': ['camiseta', 'roupa', 'vestuario'],
  '👔': ['gravata', 'roupa', 'vestuario'],
  '👖': ['calca', 'jeans', 'roupa'],
  '🧣': ['cachecol', 'roupa'],
  '🧤': ['luva', 'roupa'],
  '🧥': ['casaco', 'roupa'],
  '🧦': ['meia', 'roupa'],
  '👗': ['vestido', 'roupa'],
  '👘': ['quimono', 'roupa'],
  '🥻': ['sari', 'roupa'],
  '🩱': ['maiô', 'roupa'],
  '🩲': ['cueca', 'roupa'],
  '🩳': ['short', 'roupa'],
  '👙': ['biquini', 'roupa'],
  '👚': ['blusa', 'roupa'],
  '👛': ['bolsa', 'pequena'],
  '👜': ['bolsa', 'mao'],
  '👝': ['bolsa', 'clutch'],
  '🛍️': ['sacola', 'compras'],
  '🎒': ['mochila', 'escola'],
  '👞': ['sapato', 'masculino'],
  '👟': ['tenis', 'sapato'],
  '🥾': ['bota', 'caminhada'],
  '🥿': ['sapato', 'plano'],
  '👠': ['sapato', 'salto', 'alto'],
  '👡': ['sandalia', 'sapato'],
  '🩰': ['sapato', 'ballet'],
  '👢': ['bota', 'sapato'],
  '👑': ['coroa', 'rei'],
  '👒': ['chapeu', 'mulher'],
  '🎩': ['cartola', 'chapeu'],
  '🎓': ['formatura', 'chapeu'],
  '🧢': ['boné', 'chapeu'],
  '⛑️': ['capacete', 'seguranca'],
  '🪖': ['capacete', 'militar'],
  '💄': ['batom', 'maquiagem'],
  '💍': ['anel', 'casamento'],
  '💎': ['diamante', 'joia'],
  // Finance
  '💰': ['dinheiro', 'saco', 'financeiro'],
  '💴': ['yen', 'moeda', 'japao'],
  '💵': ['dolar', 'moeda', 'dinheiro'],
  '💶': ['euro', 'moeda'],
  '💷': ['libra', 'moeda'],
  '💸': ['dinheiro', 'asas', 'gastar'],
  '💳': ['cartao', 'credito', 'financeiro'],
  '💹': ['grafico', 'crescimento', 'financeiro'],
  '💱': ['cambio', 'moeda', 'financeiro'],
  '💲': ['simbolo', 'dolar', 'dinheiro'],
  '💼': ['maleta', 'negocios', 'trabalho'],
  // Other
  '📦': ['caixa', 'pacote', 'entrega'],
  '📫': ['caixa', 'correio', 'fechada'],
  '📪': ['caixa', 'correio', 'aberta'],
  '📬': ['caixa', 'correio', 'bandeira'],
  '📭': ['caixa', 'correio', 'bandeira', 'baixa'],
  '📮': ['caixa', 'correio', 'vermelha'],
  '✉️': ['envelope', 'carta', 'email'],
  '📧': ['email', 'mensagem'],
  '📨': ['envelope', 'entrada'],
  '📩': ['envelope', 'flecha'],
  '📤': ['envelope', 'saida'],
  '📥': ['envelope', 'entrada'],
  '⚡': ['raio', 'eletricidade', 'energia'],
  '🔥': ['fogo', 'chama', 'quente'],
  '💧': ['agua', 'gota'],
  '🌊': ['onda', 'mar', 'agua'],
  '🔧': ['chave', 'ferramenta'],
  '🔨': ['martelo', 'ferramenta'],
  '⚒️': ['martelo', 'picareta'],
  '🛠️': ['ferramentas', 'caixa'],
  '⛏️': ['picareta', 'ferramenta'],
  '🔩': ['porca', 'parafuso'],
  '⚙️': ['engrenagem', 'mecanismo'],
  '🧰': ['caixa', 'ferramentas'],
  '🧲': ['imã', 'magnetico'],
  '🪓': ['machado', 'ferramenta'],
  '💈': ['barbeiro', 'poste'],
  '⚗️': ['alambique', 'quimica'],
  '🔭': ['telescopio', 'ciencia'],
  '🕳️': ['buraco', 'negro'],
  '🧹': ['vassoura', 'limpeza'],
  '🪠': ['desentupidor', 'limpeza'],
  '🧺': ['cesta', 'roupas'],
  '🧻': ['papel', 'higienico'],
  '🚽': ['vaso', 'sanitario'],
  '🚿': ['chuveiro', 'banho'],
  '🛁': ['banheira', 'banho'],
  '🛀': ['banho', 'pessoa'],
  '🧼': ['sabonete', 'limpeza'],
  '🪒': ['gilete', 'barbear'],
  '🧽': ['esponja', 'limpeza'],
  '🪣': ['balde', 'agua'],
  '🧴': ['frasco', 'shampoo'],
  '🛎️': ['sino', 'hotel'],
  '🔑': ['chave', 'porta'],
  '🗝️': ['chave', 'antiga'],
  '🚪': ['porta', 'entrada'],
  '🪑': ['cadeira', 'mobilia'],
  '🛋️': ['sofa', 'mobilia'],
  '🛏️': ['cama', 'mobilia'],
  '🛌': ['cama', 'dormir'],
  '🧸': ['ursinho', 'brinquedo'],
  '🪆': ['matryoshka', 'boneca'],
  '🪅': ['piñata', 'festa'],
  '🪇': ['maracas', 'musica'],
  '🪈': ['flauta', 'musica'],
  '🪐': ['saturno', 'planeta'],
  '🌍': ['terra', 'europa', 'africa'],
  '🌎': ['terra', 'americas'],
  '🌏': ['terra', 'asia', 'australia'],
  '🌐': ['globo', 'internet'],
  '🗺️': ['mapa', 'mundo'],
  '🧭': ['bussola', 'navegacao'],
  '🏔️': ['montanha', 'neve'],
  '⛰️': ['montanha', 'natureza'],
  '🌋': ['vulcao', 'montanha'],
  '🗻': ['fuji', 'montanha'],
  '🏕️': ['acampamento', 'tenda'],
  '🏖️': ['praia', 'guarda', 'sol'],
  '🏜️': ['deserto', 'natureza'],
  '🏝️': ['ilha', 'deserta'],
  '🏞️': ['parque', 'nacional'],
  '🏟️': ['estadio', 'esporte'],
  '🏛️': ['templo', 'grego'],
  '🏗️': ['construcao', 'edificio'],
  '🧱': ['tijolo', 'construcao'],
  '🪨': ['pedra', 'rocha'],
  '🪵': ['madeira', 'tronco'],
  '🛖': ['cabana', 'casa'],
  '⛲': ['fonte', 'agua'],
  '⛺': ['tenda', 'acampamento'],
  '♨️': ['onsen', 'agua', 'quente']
}

const filteredEmojis = computed(() => {
  let emojis = []
  
  if (selectedCategory.value === 'all') {
    emojis = categories[0].emojis
  } else {
    const category = categories.find(cat => cat.id === selectedCategory.value)
    emojis = category ? category.emojis : []
  }
  
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.trim().toLowerCase()
    return emojis.filter(emoji => {
      const keywords = emojiKeywords[emoji] || []
      return keywords.some(keyword => keyword.includes(query))
    })
  }
  
  return emojis
})

function selectEmoji(emoji) {
  emit('update:modelValue', emoji)
  emit('close')
}

function handleClickOutside(event) {
  if (pickerRef.value && !pickerRef.value.contains(event.target)) {
    // Use setTimeout to avoid conflicts with button clicks that open the picker
    setTimeout(() => {
      emit('close')
    }, 0)
  }
}

onMounted(() => {
  // Use capture phase to catch clicks before they bubble
  setTimeout(() => {
    document.addEventListener('click', handleClickOutside, true)
  }, 100)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside, true)
})
</script>

<style scoped>
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.scrollbar-hide::-webkit-scrollbar {
  display: none;
}
</style>

