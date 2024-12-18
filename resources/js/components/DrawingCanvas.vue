<template>
  <div class="drawing-canvas-container">
    <div class="bg-white p-4 rounded-lg shadow">
      <h2 class="text-xl mb-4">Drawing Canvas</h2>
      <div class="canvas-wrapper" ref="canvasWrapper">
        <canvas ref="canvas" width="800" height="600"></canvas>
      </div>
      <div class="drawing-tools mt-4 space-x-2">
        <div class="tool-group">
          <button @click="setTool('brush')" class="px-4 py-2 bg-gray-200 rounded" :class="{ 'bg-blue-500 text-white': currentTool === 'brush' }">Brush</button>
          <button @click="setTool('eraser')" class="px-4 py-2 bg-gray-200 rounded" :class="{ 'bg-blue-500 text-white': currentTool === 'eraser' }">Eraser</button>
        </div>
        <div class="color-group">
          <button @click="setColor('black')" class="w-8 h-8 rounded-full bg-black" :class="{ 'ring-2 ring-blue-500': currentColor === 'black' }"></button>
          <button @click="setColor('red')" class="w-8 h-8 rounded-full bg-red-500" :class="{ 'ring-2 ring-blue-500': currentColor === 'red' }"></button>
          <button @click="setColor('blue')" class="w-8 h-8 rounded-full bg-blue-500" :class="{ 'ring-2 ring-blue-500': currentColor === 'blue' }"></button>
          <button @click="setColor('green')" class="w-8 h-8 rounded-full bg-green-500" :class="{ 'ring-2 ring-blue-500': currentColor === 'green' }"></button>
        </div>
        <div class="size-group inline-flex items-center space-x-2">
          <input type="range" min="1" max="20" v-model="brushSize" class="w-32" />
          <span class="text-sm text-gray-600">Size: {{ brushSize }}</span>
        </div>
        <div class="history-group">
          <button @click="undo" class="px-4 py-2 bg-gray-200 rounded" :disabled="!canUndo">Undo</button>
          <button @click="redo" class="px-4 py-2 bg-gray-200 rounded" :disabled="!canRedo">Redo</button>
        </div>
        <button @click="clearCanvas" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600">Clear</button>
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
      echo: null,
      receivedChunks: {},
      currentTool: 'brush',
      history: [],
      redoStack: [],
      isUndoRedo: false
    }
  },
  mounted() {
    this.status = 'Component mounted';
    this.$nextTick(() => {
      this.initCanvas();
      this.initWebSocket();
    });
  },
  methods: {
    initCanvas() {
      try {
        this.canvas = new fabric.Canvas(this.$refs.canvas, {
          isDrawingMode: true,
          width: 800,
          height: 600
        });

        this.canvas.freeDrawingBrush.width = this.brushSize;
        this.canvas.freeDrawingBrush.color = this.currentColor;

        this.canvas.on('path:created', (e) => {
          if (!this.isUndoRedo) {
            this.history.push(e.path);
            this.redoStack = [];
            this.broadcastDrawing(e.path);
          }
          this.isUndoRedo = false;
        });

        this.status = 'Canvas initialized';
      } catch (error) {
        console.error('Error initializing canvas:', error);
        this.status = 'Error initializing canvas';
      }
    },
    initWebSocket() {
      window.Echo.join(`drawing`)
        .here((users) => {
          this.activeUsers = users;
          this.currentUserId = window.Laravel.user.id;
          this.status = `Connected with ${users.length} users`;
        })
        .joining((user) => {
          this.activeUsers.push(user);
          this.status = `${user.name} joined`;
        })
        .leaving((user) => {
          this.activeUsers = this.activeUsers.filter(u => u.id !== user.id);
          this.status = `${user.name} left`;
        })
        .error((error) => {
          console.error('Presence channel error:', error);
          this.status = 'Error connecting to presence channel';
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
      const maxSize = 8000;
      const chunks = [];
      let pendingPoints = [...pathData.path];
      
      while (pendingPoints.length > 0) {
        let currentChunk = {
          ...pathData,
          path: [],
          isChunk: true,
          chunkIndex: chunks.length
        };

        if (chunks.length === 0 || !chunks[chunks.length - 1].path.length) {
          const firstPoint = pendingPoints[0];
          currentChunk.path.push(['M', firstPoint[1], firstPoint[2]]);
          pendingPoints.shift();
        } else {
          const lastChunk = chunks[chunks.length - 1];
          const lastPoint = lastChunk.path[lastChunk.path.length - 1];
          currentChunk.path.push(['M', lastPoint[1], lastPoint[2]]);
        }

        while (pendingPoints.length > 0) {
          const point = pendingPoints[0];
          const testChunk = {
            ...currentChunk,
            path: [...currentChunk.path, point]
          };
          
          const chunkSize = new TextEncoder().encode(JSON.stringify(testChunk)).length;
          if (chunkSize >= maxSize && currentChunk.path.length > 1) {
            break;
          }
          
          currentChunk.path.push(point);
          pendingPoints.shift();
        }

        if (currentChunk.path.length > 0) {
          chunks.push(currentChunk);
        }
      }

      chunks.forEach((chunk, index) => {
        chunk.totalChunks = chunks.length;
        chunk.chunkIndex = index;
        chunk.drawingId = `${Date.now()}-${index}`; // Add unique drawing ID
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

      const dataSize = new TextEncoder().encode(JSON.stringify(drawingData)).length;
      
      if (dataSize > 8000) {
        const chunks = this.splitIntoChunks(drawingData);
        const drawingId = chunks[0].drawingId.split('-')[0];
        
        const sendChunks = async () => {
          for (let i = 0; i < chunks.length; i++) {
            try {
              const chunk = chunks[i];
              chunk.drawingId = `${drawingId}-${i}`; // Ensure consistent drawing ID
              
              await axios.post('/drawing/broadcast', {
                type: 'path',
                data: chunk
              });
            } catch (error) {
              console.error(`Error sending chunk ${i + 1}:`, error);
              break;
            }
          }
        };
        
        sendChunks();
      } else {
        axios.post('/drawing/broadcast', {
          type: 'path',
          data: drawingData
        }).catch(error => console.error('Error sending drawing:', error));
      }
    },
    broadcastClear() {
      try {
        axios.post('/drawing/broadcast', {
          type: 'clear',
          data: {}
        });
      } catch (error) {
        console.error('Error broadcasting clear:', error);
        this.status = 'Error broadcasting clear';
      }
    },
    broadcastAction(type, data) {
      axios.post('/drawing/broadcast', {
        type: type,
        data: data
      }).catch(error => {
        console.error(`Error broadcasting ${type}:`, error);
        this.status = `Error broadcasting ${type}`;
      });
    },
    handleDrawingEvent(event) {
      if (!event.data) return;
      
      const data = event.data;
      
      if (data.type === 'undo') {
        if (this.history.length > 0) {
          const path = this.history.find(p => p.id === data.data.pathId);
          if (path) {
            this.history = this.history.filter(p => p.id !== data.data.pathId);
            this.redoStack.push(path);
            this.isUndoRedo = true;
            this.canvas.remove(path);
          }
        }
        return;
      }

      if (data.type === 'redo') {
        const path = this.redoStack.find(p => p.id === data.data.pathId);
        if (path) {
          this.redoStack = this.redoStack.filter(p => p.id !== data.data.pathId);
          this.history.push(path);
          this.isUndoRedo = true;
          this.canvas.add(path);
        }
        return;
      }

      if (data.isChunk) {
        const drawingId = data.drawingId.split('-')[0];
        
        if (!this.receivedChunks[drawingId]) {
          this.receivedChunks[drawingId] = new Array(data.totalChunks).fill(null);
        }
        
        this.receivedChunks[drawingId][data.chunkIndex] = data;
        
        const chunks = this.receivedChunks[drawingId];
        
        if (chunks.every(chunk => chunk !== null)) {
          const completePath = chunks.reduce((acc, chunk, idx) => {
            const chunkPath = chunk.path;
            if (idx === 0) return chunkPath;
            return chunkPath[0][0] === 'M' ? [...acc, ...chunkPath.slice(1)] : [...acc, ...chunkPath];
          }, []);
          
          const completeObject = {
            ...chunks[0],
            path: completePath,
            isChunk: false
          };
          
          this.drawPath(completeObject);
          delete this.receivedChunks[drawingId];
        }
      } else {
        this.drawPath(data);
      }
    },
    drawPath(data) {
      const fullObject = {
        ...data,
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

      if (data.type === 'path') {
        const pathObject = new fabric.Path(data.path.map(point => point.join(' ')).join(' '), fullObject);
        this.canvas.add(pathObject);
        this.canvas.renderAll();
      }
    },
    setTool(tool) {
      this.currentTool = tool;
      if (tool === 'eraser') {
        this.canvas.freeDrawingBrush.color = '#ffffff';
        this.canvas.freeDrawingBrush.width = this.brushSize * 2;
      } else {
        this.canvas.freeDrawingBrush.color = this.currentColor;
        this.canvas.freeDrawingBrush.width = this.brushSize;
      }
    },
    undo() {
      if (this.canUndo) {
        const path = this.history.pop();
        this.redoStack.push(path);
        this.isUndoRedo = true;
        this.canvas.remove(path);
        this.broadcastAction('undo', { pathId: path.id });
      }
    },
    redo() {
      if (this.canRedo) {
        const path = this.redoStack.pop();
        this.history.push(path);
        this.isUndoRedo = true;
        this.canvas.add(path);
        this.broadcastAction('redo', { pathId: path.id });
      }
    }
  },
  computed: {
    canUndo() {
      return this.history.length > 0;
    },
    canRedo() {
      return this.redoStack.length > 0;
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
