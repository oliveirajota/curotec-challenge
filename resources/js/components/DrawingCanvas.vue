<template>
  <div class="drawing-canvas-container">
    <div class="bg-white p-4 rounded-lg shadow">
      <h2 class="text-xl mb-4">Drawing Canvas</h2>
      <div class="canvas-wrapper" ref="canvasWrapper">
        <canvas ref="canvas" width="800" height="600"></canvas>
      </div>
      <div class="drawing-tools mt-4 space-x-2">
        <button @click="clearCanvas" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600">Clear</button>
        <button @click="setColor('black')" class="w-8 h-8 rounded-full bg-black" :class="{ 'ring-2 ring-blue-500': currentColor === 'black' }"></button>
        <button @click="setColor('red')" class="w-8 h-8 rounded-full bg-red-500" :class="{ 'ring-2 ring-blue-500': currentColor === 'red' }"></button>
        <button @click="setColor('blue')" class="w-8 h-8 rounded-full bg-blue-500" :class="{ 'ring-2 ring-blue-500': currentColor === 'blue' }"></button>
        <div class="inline-flex items-center space-x-2">
          <input type="range" min="1" max="20" v-model="brushSize" class="w-32" />
          <span class="text-sm text-gray-600">Size: {{ brushSize }}</span>
        </div>
      </div>
      <div class="mt-2 text-sm text-gray-600">
        Status: {{ status }}
      </div>
      <div v-if="activeUsers.length > 0" class="mt-2">
        <h3 class="text-sm font-semibold">Active Users:</h3>
        <ul class="text-sm text-gray-600">
          <li v-for="user in activeUsers" :key="user.id">
            {{ user.name }} {{ user.id === currentUserId ? '(You)' : '' }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script>
import { fabric } from 'fabric';
import Echo from 'laravel-echo';

export default {
  name: 'DrawingCanvas',
  data() {
    return {
      status: 'Initializing...',
      canvas: null,
      currentColor: 'black',
      brushSize: 5,
      activeUsers: [],
      currentUserId: null,
      echo: null
    }
  },
  mounted() {
    console.log('DrawingCanvas component mounted');
    this.status = 'Component mounted';
    this.$nextTick(() => {
      this.initCanvas();
      this.initWebSocket();
    });
  },
  methods: {
    initCanvas() {
      try {
        console.log('Initializing Fabric canvas');
        this.canvas = new fabric.Canvas(this.$refs.canvas, {
          isDrawingMode: true,
          width: 800,
          height: 600
        });

        this.canvas.freeDrawingBrush.color = this.currentColor;
        this.canvas.freeDrawingBrush.width = this.brushSize;

        // Listen for path creation
        this.canvas.on('path:created', (e) => {
          this.broadcastDrawing(e.path);
        });

        this.status = 'Canvas initialized';
      } catch (error) {
        console.error('Error initializing canvas:', error);
        this.status = 'Error initializing canvas';
      }
    },
    initWebSocket() {
      window.Echo.join('drawing')
        .here((users) => {
          this.activeUsers = users;
          this.currentUserId = window.Laravel.user.id;
        })
        .joining((user) => {
          this.activeUsers.push(user);
        })
        .leaving((user) => {
          this.activeUsers = this.activeUsers.filter(u => u.id !== user.id);
        })
        .listen('DrawingEvent', (event) => {
          this.handleDrawingEvent(event);
        });
    },
    clearCanvas() {
      this.canvas.clear();
      this.broadcastClear();
    },
    setColor(color) {
      this.currentColor = color;
      if (this.canvas) {
        this.canvas.freeDrawingBrush.color = color;
      }
    },
    async broadcastDrawing(path) {
      try {
        const pathData = path.toObject();
        await axios.post('/drawing/broadcast', {
          type: 'path',
          data: pathData
        });
      } catch (error) {
        console.error('Error broadcasting drawing:', error);
      }
    },
    async broadcastClear() {
      try {
        await axios.post('/drawing/broadcast', {
          type: 'clear',
          data: {}
        });
      } catch (error) {
        console.error('Error broadcasting clear:', error);
      }
    },
    handleDrawingEvent(event) {
      if (event.type === 'path') {
        fabric.util.enlivenObjects([event.data], (objects) => {
          const path = objects[0];
          this.canvas.add(path);
          this.canvas.renderAll();
        });
      } else if (event.type === 'clear') {
        this.canvas.clear();
      }
    }
  },
  watch: {
    brushSize(newSize) {
      if (this.canvas) {
        this.canvas.freeDrawingBrush.width = parseInt(newSize);
      }
    }
  },
  beforeUnmount() {
    if (this.canvas) {
      this.canvas.dispose();
    }
  }
}
</script>

<style scoped>
.drawing-canvas-container {
  max-width: 900px;
  margin: 0 auto;
}

.canvas-wrapper {
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  overflow: hidden;
}

canvas {
  display: block;
  background: white;
}

.drawing-tools {
  display: flex;
  align-items: center;
}

.drawing-tools button {
  transition: all 0.2s;
}

.drawing-tools button:hover {
  transform: scale(1.1);
}
</style>
