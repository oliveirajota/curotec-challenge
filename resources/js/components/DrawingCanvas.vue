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
      console.log('Initializing WebSocket connection');
      window.Echo.join(`drawing`)
        .here((users) => {
          console.log('Users currently in channel:', users);
          this.activeUsers = users;
          this.currentUserId = window.Laravel.user.id;
          this.status = `Connected with ${users.length} users`;
        })
        .joining((user) => {
          console.log('User joining:', user);
          this.activeUsers.push(user);
          this.status = `${user.name} joined`;
        })
        .leaving((user) => {
          console.log('User leaving:', user);
          this.activeUsers = this.activeUsers.filter(u => u.id !== user.id);
          this.status = `${user.name} left`;
        })
        .error((error) => {
          console.error('Presence channel error:', error);
          this.status = 'Error connecting to presence channel';
        })
        .listen('DrawingEvent', (event) => {
          console.log('Received drawing event:', event);
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
    compressPath(path) {
      if (!path || !Array.isArray(path)) return path;
      
      return path.map(point => {
        if (!Array.isArray(point)) return point;
        return [
          point[0], // Keep the command (M, L, etc.)
          Math.round(point[1] * 10) / 10, // Round x to 1 decimal
          Math.round(point[2] * 10) / 10  // Round y to 1 decimal
        ];
      });
    },
    splitIntoChunks(pathData) {
      const maxSize = 8000; // Leave buffer for other data
      const chunks = [];
      let currentChunk = null;
      let pendingPoints = [...pathData.path];
      
      const createChunk = (points) => ({
        ...pathData,
        path: points,
        isChunk: true,
        chunkIndex: chunks.length
      });

      while (pendingPoints.length > 0) {
        if (!currentChunk) {
          currentChunk = createChunk([]);
        }

        // Always include the first point of a new chunk
        if (currentChunk.path.length === 0) {
          const firstPoint = pendingPoints[0];
          currentChunk.path.push(['M', firstPoint[1], firstPoint[2]]);
          pendingPoints.shift();
          continue;
        }

        // Try adding next point
        const nextPoint = pendingPoints[0];
        const testChunk = createChunk([...currentChunk.path, nextPoint]);
        const chunkSize = new TextEncoder().encode(JSON.stringify(testChunk)).length;

        if (chunkSize < maxSize) {
          currentChunk.path.push(nextPoint);
          pendingPoints.shift();
        } else {
          // Current chunk is full, start a new one
          chunks.push(currentChunk);
          currentChunk = null;
        }
      }

      // Add the last chunk if it has points
      if (currentChunk && currentChunk.path.length > 0) {
        chunks.push(currentChunk);
      }

      // Update total chunks count
      chunks.forEach(chunk => {
        chunk.totalChunks = chunks.length;
      });

      return chunks;
    },
    broadcastDrawing(obj) {
      if (!obj || !obj.path) return;

      const drawingData = {
        type: obj.type,
        path: obj.path,
        left: Math.round(obj.left),
        top: Math.round(obj.top),
        width: Math.round(obj.width || 0),
        height: Math.round(obj.height || 0),
        stroke: obj.stroke,
        strokeWidth: obj.strokeWidth
      };

      // Check if we need to split the data
      const dataSize = new TextEncoder().encode(JSON.stringify(drawingData)).length;
      if (dataSize > 8000) {
        const chunks = this.splitIntoChunks(drawingData);
        chunks.forEach((chunk, index) => {
          axios.post('/drawing/broadcast', {
            type: 'path',
            data: chunk
          })
          .then(() => {
            console.log(`Chunk ${index + 1}/${chunks.length} sent`);
          })
          .catch(error => {
            console.error(`Error sending chunk ${index + 1}:`, error);
          });
        });
      } else {
        // Small enough to send as is
        axios.post('/drawing/broadcast', {
          type: 'path',
          data: drawingData
        })
        .then(() => {
          console.log('Drawing sent successfully');
        })
        .catch(error => {
          console.error('Error sending drawing:', error);
        });
      }
    },
    broadcastClear() {
      try {
        console.log('Broadcasting clear command');
        axios.post('/drawing/broadcast', {
          type: 'clear',
          data: {}
        });
        console.log('Clear broadcast successful');
      } catch (error) {
        console.error('Error broadcasting clear:', error);
        this.status = 'Error broadcasting clear';
      }
    },
    handleDrawingEvent(event) {
      if (!event.data) return;
      
      const fullObject = {
        ...event.data,
        fill: null,
        strokeLineCap: 'round',
        strokeLineJoin: 'round',
        strokeDashArray: null,
        strokeDashOffset: 0,
        strokeMiterLimit: 10,
        scaleX: 1,
        scaleY: 1,
        angle: 0,
        flipX: false,
        flipY: false,
        opacity: 1,
        shadow: null,
        visible: true,
        backgroundColor: null,
        fillRule: 'nonzero',
        paintFirst: 'fill',
        globalCompositeOperation: 'source-over',
        skewX: 0,
        skewY: 0,
      };

      if (event.data.type === 'path') {
        const pathObject = new fabric.Path(event.data.path.map(point => point.join(' ')).join(' '), fullObject);
        this.canvas.add(pathObject);
        this.canvas.renderAll();
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
