<template>
  <div class="drawing-canvas-container">
    <div class="bg-white p-4 rounded-lg shadow">
      <h2 class="text-xl mb-4">Drawing Canvas</h2>
      <div class="canvas-wrapper" ref="canvasWrapper">
        <canvas ref="canvas" width="800" height="600"></canvas>
      </div>
      <div class="drawing-tools mt-4 space-x-2">
        <div class="flex space-x-4 mb-4">
          <button @click="setTool('brush')" :class="{ 'bg-blue-500': tool === 'brush', 'bg-gray-200': tool !== 'brush' }" class="px-4 py-2 rounded">
            Brush
          </button>
          <button @click="setTool('eraser')" :class="{ 'bg-blue-500': tool === 'eraser', 'bg-gray-200': tool !== 'eraser' }" class="px-4 py-2 rounded">
            Eraser
          </button>
        </div>
        <div class="flex space-x-4 mb-4">
          <button @click="setColor('#000000')" :class="{ 'ring-2 ring-blue-500': color === '#000000' }" class="w-8 h-8 bg-black rounded-full"></button>
          <button @click="setColor('#FF0000')" :class="{ 'ring-2 ring-blue-500': color === '#FF0000' }" class="w-8 h-8 bg-red-500 rounded-full"></button>
          <button @click="setColor('#0000FF')" :class="{ 'ring-2 ring-blue-500': color === '#0000FF' }" class="w-8 h-8 bg-blue-500 rounded-full"></button>
          <button @click="setColor('#00FF00')" :class="{ 'ring-2 ring-blue-500': color === '#00FF00' }" class="w-8 h-8 bg-green-500 rounded-full"></button>
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
      canvas: null,
      isDrawing: false,
      color: '#000000',
      brushSize: 5,
      tool: 'brush',
      history: [],
      redoStack: [],
      isUndoRedo: false,
      status: '',
      sessionId: Date.now().toString(), // Unique session ID for this drawing
      userId: null, // User ID for this session
    }
  },
  mounted() {
    // Get user ID from auth data
    if (window.Laravel && window.Laravel.user) {
      this.userId = window.Laravel.user.id;
    }

    this.initCanvas();
    this.loadHistory();
    
    // Subscribe to the presence channel
    window.Echo.join('drawing')
      .here((users) => {
        console.log('Users currently drawing:', users);
      })
      .joining((user) => {
        console.log('User joined:', user);
      })
      .leaving((user) => {
        console.log('User left:', user);
      })
      .listen('.drawing-event', (event) => {
        this.handleDrawingEvent(event);
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
        this.canvas.freeDrawingBrush.color = this.color;

        // Listen for mouse:up event instead of path:created
        this.canvas.on('mouse:up', () => {
          if (this.canvas.isDrawingMode) {
            const objects = this.canvas.getObjects();
            const lastPath = objects[objects.length - 1];
            
            if (lastPath && !this.isUndoRedo) {
              console.log('New path detected:', lastPath);
              this.history.push(lastPath);
              this.redoStack = [];
              this.broadcastDrawing(lastPath);
            }
            this.isUndoRedo = false;
          }
        });

        this.status = 'Canvas initialized';
      } catch (error) {
        console.error('Error initializing canvas:', error);
        this.status = 'Error initializing canvas';
      }
    },
    async loadHistory() {
      try {
        const response = await axios.get('/drawing/history', {
          params: { session_id: this.sessionId }
        });
        const steps = response.data.steps;
        
        // Apply each step to the canvas
        for (const step of steps) {
          this.applyStep(step);
        }
      } catch (error) {
        console.error('Error loading history:', error);
      }
    },
    applyStep(step) {
      const data = step.content;
      if (!data || !data.path) return;

      fabric.Path.fromObject(data.path, (path) => {
        path.id = step.step;
        this.canvas.add(path);
        this.history.push(path);
        this.canvas.renderAll();
      });
    },
    async broadcastDrawing(obj) {
      try {
        console.log('Broadcasting path:', obj);
        const pathData = {
          path: obj.path,
          left: obj.left,
          top: obj.top,
          width: obj.width,
          height: obj.height,
          stroke: obj.stroke,
          strokeWidth: obj.strokeWidth,
          fill: false,
          closed: false
        };

        const data = {
          type: 'path',
          data: pathData,
          session_id: this.sessionId,
          user_id: this.userId
        };

        console.log('Broadcasting data:', data);
        const response = await axios.post('/drawing/broadcast', data);
        console.log('Broadcast response:', response.data);
        this.status = 'Drawing broadcasted';
      } catch (error) {
        console.error('Error broadcasting drawing:', error);
        this.status = 'Error broadcasting drawing';
      }
    },
    async undo() {
      if (this.history.length > 0) {
        const path = this.history[this.history.length - 1];
        try {
          const response = await axios.post('/drawing/undo', {
            session_id: this.sessionId
          });
          
          if (response.data.status === 'success') {
            this.history.pop();
            this.redoStack.push(path);
            this.isUndoRedo = true;
            this.canvas.remove(path);
          }
        } catch (error) {
          console.error('Error during undo:', error);
        }
      }
    },
    async redo() {
      if (this.redoStack.length > 0) {
        const path = this.redoStack[this.redoStack.length - 1];
        try {
          const response = await axios.post('/drawing/redo', {
            session_id: this.sessionId
          });
          
          if (response.data.status === 'success') {
            this.redoStack.pop();
            this.history.push(path);
            this.isUndoRedo = true;
            this.canvas.add(path);
          }
        } catch (error) {
          console.error('Error during redo:', error);
        }
      }
    },
    handleDrawingEvent(event) {
      try {
        if (event.userId === this.userId) return; // Skip our own events
        
        console.log('Received drawing event:', event);
        const pathData = event.data;

        // Create a new path with the exact properties from the event
        const newPath = new fabric.Path(pathData.path, {
          left: pathData.left,
          top: pathData.top,
          width: pathData.width,
          height: pathData.height,
          stroke: pathData.stroke,
          strokeWidth: pathData.strokeWidth,
          fill: false,
          closed: false,
          selectable: false,
          evented: false
        });

        console.log('Creating path from event:', newPath);
        this.canvas.add(newPath);
        this.canvas.renderAll();
        this.status = 'Drawing received';
      } catch (error) {
        console.error('Error handling drawing event:', error);
        this.status = 'Error handling drawing event';
      }
    },
    setTool(tool) {
      this.tool = tool;
      if (tool === 'eraser') {
        this.canvas.freeDrawingBrush.color = '#ffffff';
        this.canvas.freeDrawingBrush.width = this.brushSize * 2;
      } else {
        this.canvas.freeDrawingBrush.color = this.color;
        this.canvas.freeDrawingBrush.width = this.brushSize;
      }
    },
    setColor(color) {
      this.color = color;
      if (this.canvas && this.tool !== 'eraser') {
        this.canvas.freeDrawingBrush.color = color;
      }
    },
    clearCanvas() {
      this.canvas.clear();
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
