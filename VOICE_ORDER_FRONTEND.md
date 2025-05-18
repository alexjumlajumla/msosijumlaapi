# Voice Order System Frontend - Modern Chat Interface

## Overview

This guide outlines the implementation of a modern, OpenAI-style voice ordering interface for your food ordering system. The new interface provides:

1. A chat-like conversational UI for both voice and text input
2. Real-time audio transcription with visual feedback
3. AI-powered food recommendations with product cards
4. Transparent confidence scoring and error handling
5. Seamless integration with your existing cart system

## Technology Stack

- **Frontend Framework**: Next.js
- **UI Components**: Tailwind CSS, shadcn/ui (or MUI/Material UI)
- **State Management**: React Context API or Zustand/Redux
- **API Client**: Axios or React Query
- **Audio Processing**: Web Audio API, MediaRecorder API

## UI Components Architecture

### Core Components

1. **`VoiceOrderPage`** - Main page container
2. **`ChatInterface`** - Manages conversation, messages, and history
3. **`AudioRecorder`** - Handles voice recording and transcription
4. **`ProductRecommendations`** - Displays AI-suggested products
5. **`ConfidenceIndicator`** - Shows AI confidence levels

### Supporting Components

1. **`MessageBubble`** - Individual message bubbles
2. **`ProductCard`** - Individual product cards
3. **`AudioVisualizer`** - Waveform display during recording
4. **`LoadingIndicator`** - Animated loading states
5. **`ErrorDisplay`** - User-friendly error messages

## API Integration

### Voice Order Endpoints

| Endpoint | Method | Description | Auth |
|----------|--------|-------------|------|
| `/api/v1/voice-order` | POST | Process voice recordings | Optional |
| `/api/v1/voice-order/{id}/retry` | POST | Retry processing a voice order | Required |
| `/api/v1/voice-order/feedback` | POST | Submit feedback on recommendations | Required |
| `/api/v1/voice-order/{id}/mark-fulfilled` | POST | Mark order as fulfilled | Admin |
| `/api/v1/voice-order/{id}/assign-agent` | POST | Assign agent to order | Admin |
| `/api/v1/voice-order/stats` | GET | Get voice order statistics | Admin |
| `/api/v1/voice-order/user/{id}` | GET | Get user's voice orders | Admin/Self |

## Modern Chat Interface Implementation

### Page Layout

```tsx
// pages/voice-order.tsx
import { useState } from 'react';
import { ChatInterface } from '@/components/VoiceOrder/ChatInterface';
import { Header } from '@/components/Layout/Header';
import { Footer } from '@/components/Layout/Footer';

export default function VoiceOrderPage() {
  return (
    <div className="flex flex-col min-h-screen bg-gray-50">
      <Header title="Voice Order Assistant" />
      
      <main className="flex-grow container mx-auto px-4 py-8 max-w-4xl">
        <ChatInterface />
      </main>
      
      <Footer />
    </div>
  );
}
```

### Chat Interface Component

```tsx
// components/VoiceOrder/ChatInterface.tsx
import { useState, useRef, useEffect } from 'react';
import { useRouter } from 'next/router';
import { useSession } from 'next-auth/react';
import { MessageBubble } from './MessageBubble';
import { AudioRecorder } from './AudioRecorder';
import { ProductRecommendations } from './ProductRecommendations';
import { ConfidenceIndicator } from './ConfidenceIndicator';
import { 
  Mic, MicOff, Send, Loader2, RefreshCw, ShoppingCart
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';

type Message = {
  id: string;
  role: 'user' | 'assistant' | 'system' | 'error';
  content: string;
  timestamp: Date;
  products?: any[];
  confidenceScore?: number;
};

export function ChatInterface() {
  const { data: session } = useSession();
  const router = useRouter();
  const [messages, setMessages] = useState<Message[]>([
    {
      id: '1',
      role: 'assistant',
      content: 'Hi! What would you like to order today? You can speak or type your request.',
      timestamp: new Date(),
    }
  ]);
  const [inputText, setInputText] = useState('');
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [currentTranscript, setCurrentTranscript] = useState('');
  const [sessionId, setSessionId] = useState(() => Math.random().toString(36).substring(2, 15));
  const messagesEndRef = useRef<HTMLDivElement>(null);
  
  // Scroll to bottom of messages
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);
  
  const handleSendMessage = async (text: string) => {
    if (!text.trim()) return;
    
    // Add user message
    const userMessage: Message = {
      id: Math.random().toString(36).substring(2, 15),
      role: 'user',
      content: text,
      timestamp: new Date(),
    };
    
    setMessages(prev => [...prev, userMessage]);
    setInputText('');
    setIsProcessing(true);
    
    try {
      // Call text-based AI ordering API
      const response = await axios.post('/api/v1/ai-chat', {
        message: text,
        session_id: sessionId,
      }, {
        headers: session?.user ? {
          Authorization: `Bearer ${session.accessToken}`
        } : undefined
      });
      
      if (response.data.success) {
        // Add assistant response
        const assistantMessage: Message = {
          id: Math.random().toString(36).substring(2, 15),
          role: 'assistant',
          content: response.data.recommendation_text,
          timestamp: new Date(),
          products: response.data.recommendations,
          confidenceScore: response.data.confidence_score,
        };
        
        setMessages(prev => [...prev, assistantMessage]);
      } else {
        throw new Error(response.data.message || 'Failed to process your request');
      }
    } catch (error) {
      console.error('Error processing text request:', error);
      
      // Add error message
      const errorMessage: Message = {
        id: Math.random().toString(36).substring(2, 15),
        role: 'error',
        content: `Sorry, I couldn't process your request. ${error.message}`,
        timestamp: new Date(),
      };
      
      setMessages(prev => [...prev, errorMessage]);
    } finally {
      setIsProcessing(false);
    }
  };
  
  const handleVoiceResult = (result: any) => {
    if (result.transcription) {
      // Add user message with transcription
      const userMessage: Message = {
        id: Math.random().toString(36).substring(2, 15),
        role: 'user',
        content: result.transcription,
        timestamp: new Date(),
      };
      
      // Add assistant response
      const assistantMessage: Message = {
        id: Math.random().toString(36).substring(2, 15),
        role: 'assistant',
        content: result.recommendation_text,
        timestamp: new Date(),
        products: result.recommendations,
        confidenceScore: result.confidence_score,
      };
      
      setMessages(prev => [...prev, userMessage, assistantMessage]);
    } else if (result.error) {
      // Add error message
      const errorMessage: Message = {
        id: Math.random().toString(36).substring(2, 15),
        role: 'error',
        content: `Sorry, I couldn't process your voice request. ${result.error}`,
        timestamp: new Date(),
      };
      
      setMessages(prev => [...prev, errorMessage]);
    }
  };
  
  const addToCart = async (productId: number, quantity: number = 1) => {
    try {
      // Call your existing cart API
      const response = await axios.post('/api/cart/add', {
        product_id: productId,
        quantity: quantity
      }, {
        headers: session?.user ? {
          Authorization: `Bearer ${session.accessToken}`
        } : undefined
      });
      
      if (response.data.success) {
        // Show success message
        const successMessage: Message = {
          id: Math.random().toString(36).substring(2, 15),
          role: 'system',
          content: `Added ${quantity} item(s) to your cart!`,
          timestamp: new Date(),
        };
        
        setMessages(prev => [...prev, successMessage]);
      }
    } catch (error) {
      console.error('Error adding to cart:', error);
    }
  };
  
  return (
    <div className="rounded-lg border bg-card shadow-sm flex flex-col h-[80vh]">
      {/* Message history */}
      <div className="flex-grow overflow-y-auto p-4 space-y-4">
        {messages.map(message => (
          <MessageBubble
            key={message.id}
            message={message}
            onAddToCart={addToCart}
          />
        ))}
        
        {/* Realtime transcript display */}
        {isRecording && currentTranscript && (
          <div className="bg-gray-100 rounded-lg p-3 italic text-gray-700">
            Hearing: {currentTranscript}...
          </div>
        )}
        
        {/* Processing indicator */}
        {isProcessing && (
          <div className="flex items-center space-x-2 text-gray-500">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span>Processing your request...</span>
          </div>
        )}
        
        <div ref={messagesEndRef} />
      </div>
      
      {/* Input area */}
      <div className="p-4 border-t">
        {isRecording ? (
          <div className="flex flex-col space-y-2">
            <AudioRecorder 
              isRecording={isRecording}
              onTranscriptUpdate={setCurrentTranscript}
              onResult={handleVoiceResult}
              onStop={() => setIsRecording(false)}
              sessionId={sessionId}
            />
            <Button 
              variant="destructive" 
              onClick={() => setIsRecording(false)}
              className="w-full"
            >
              <MicOff className="h-4 w-4 mr-2" /> Stop Recording
            </Button>
          </div>
        ) : (
          <div className="flex space-x-2">
            <Textarea
              value={inputText}
              onChange={(e) => setInputText(e.target.value)}
              placeholder="Type your order here..."
              className="flex-grow"
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault();
                  handleSendMessage(inputText);
                }
              }}
            />
            <div className="flex flex-col space-y-2">
              <Button 
                onClick={() => handleSendMessage(inputText)}
                disabled={!inputText.trim() || isProcessing}
              >
                <Send className="h-4 w-4" />
              </Button>
              <Button 
                variant="outline" 
                onClick={() => setIsRecording(true)}
              >
                <Mic className="h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
```

### Product Recommendations Component

```tsx
// components/VoiceOrder/ProductRecommendations.tsx
import Image from 'next/image';
import { Button } from '@/components/ui/button';
import { PlusCircle, ShoppingCart } from 'lucide-react';
import { ConfidenceIndicator } from './ConfidenceIndicator';

type ProductRecommendationsProps = {
  products: any[];
  onAddToCart: (productId: number, quantity?: number) => void;
  confidenceScore?: number;
};

export function ProductRecommendations({ 
  products, 
  onAddToCart,
  confidenceScore 
}: ProductRecommendationsProps) {
  if (!products || products.length === 0) return null;
  
  return (
    <div className="mt-2 space-y-4">
      {confidenceScore !== undefined && (
        <ConfidenceIndicator score={confidenceScore} />
      )}
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {products.slice(0, 4).map(product => (
          <div 
            key={product.id}
            className="flex rounded-lg border overflow-hidden shadow-sm hover:shadow-md transition-shadow"
          >
            {product.image_url && (
              <div className="w-24 h-24 relative">
                <Image
                  src={product.image_url}
                  alt={product.translation?.title || product.title}
                  fill
                  sizes="96px"
                  className="object-cover"
                />
              </div>
            )}
            
            <div className="flex-grow p-3 flex flex-col justify-between">
              <div>
                <h4 className="font-medium">
                  {product.translation?.title || product.title}
                </h4>
                <p className="text-sm text-gray-500 line-clamp-1">
                  {product.translation?.description || product.description}
                </p>
                <p className="font-bold mt-1">
                  ${product.price?.toFixed(2) || '0.00'}
                </p>
              </div>
              
              <Button 
                size="sm" 
                onClick={() => onAddToCart(product.id)}
                className="mt-2 self-end"
              >
                <PlusCircle className="h-4 w-4 mr-1" /> Add
              </Button>
            </div>
          </div>
        ))}
      </div>
      
      {products.length > 4 && (
        <p className="text-sm text-gray-500">
          + {products.length - 4} more recommendations
        </p>
      )}
    </div>
  );
}
```

### Audio Recorder Component

```tsx
// components/VoiceOrder/AudioRecorder.tsx
import { useState, useRef, useEffect } from 'react';
import axios from 'axios';
import { useSession } from 'next-auth/react';
import { Loader2 } from 'lucide-react';

type AudioRecorderProps = {
  isRecording: boolean;
  onTranscriptUpdate: (transcript: string) => void;
  onResult: (result: any) => void;
  onStop: () => void;
  sessionId: string;
};

export function AudioRecorder({
  isRecording,
  onTranscriptUpdate,
  onResult,
  onStop,
  sessionId
}: AudioRecorderProps) {
  const { data: session } = useSession();
  const [error, setError] = useState<string | null>(null);
  const [audioLevel, setAudioLevel] = useState(0);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioContextRef = useRef<AudioContext | null>(null);
  const analyserRef = useRef<AnalyserNode | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const streamRef = useRef<MediaStream | null>(null);
  
  // Set up audio recording
  useEffect(() => {
    if (isRecording) {
      startRecording();
    } else if (mediaRecorderRef.current) {
      stopRecording();
    }
    
    return () => {
      cleanupAudio();
    };
  }, [isRecording]);
  
  // Set up audio visualization
  useEffect(() => {
    if (streamRef.current && isRecording) {
      visualizeAudio();
    }
  }, [streamRef.current, isRecording]);
  
  const startRecording = async () => {
    try {
      setError(null);
      
      // Get microphone access
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      streamRef.current = stream;
      
      // Set up audio context for visualization
      const audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
      audioContextRef.current = audioContext;
      
      const analyser = audioContext.createAnalyser();
      analyserRef.current = analyser;
      analyser.fftSize = 256;
      
      const source = audioContext.createMediaStreamSource(stream);
      source.connect(analyser);
      
      // Set up media recorder
      const mediaRecorder = new MediaRecorder(stream);
      mediaRecorderRef.current = mediaRecorder;
      audioChunksRef.current = [];
      
      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };
      
      mediaRecorder.onstop = async () => {
        await processAudio();
      };
      
      // Start recording
      mediaRecorder.start(200); // Collect data every 200ms
      
      // Set up speech recognition for real-time feedback
      setupSpeechRecognition();
      
    } catch (err) {
      console.error('Failed to start recording:', err);
      setError('Could not access microphone. Please ensure you have given permission.');
      onStop();
    }
  };
  
  const stopRecording = () => {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== 'inactive') {
      mediaRecorderRef.current.stop();
    }
    cleanupAudio();
  };
  
  const cleanupAudio = () => {
    // Stop all tracks
    if (streamRef.current) {
      streamRef.current.getTracks().forEach(track => track.stop());
      streamRef.current = null;
    }
    
    // Clean up audio context
    if (audioContextRef.current) {
      if (audioContextRef.current.state !== 'closed') {
        audioContextRef.current.close();
      }
      audioContextRef.current = null;
      analyserRef.current = null;
    }
  };
  
  const visualizeAudio = () => {
    if (!analyserRef.current || !isRecording) return;
    
    const analyser = analyserRef.current;
    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    
    const updateLevels = () => {
      if (!analyserRef.current || !isRecording) return;
      
      analyser.getByteFrequencyData(dataArray);
      
      // Calculate average volume
      let sum = 0;
      for (let i = 0; i < bufferLength; i++) {
        sum += dataArray[i];
      }
      const average = sum / bufferLength;
      const level = Math.min(100, Math.max(0, average / 256 * 100));
      setAudioLevel(level);
      
      // Continue animation
      requestAnimationFrame(updateLevels);
    };
    
    updateLevels();
  };
  
  const setupSpeechRecognition = () => {
    if (!('webkitSpeechRecognition' in window)) return;
    
    const SpeechRecognition = (window as any).webkitSpeechRecognition;
    const recognition = new SpeechRecognition();
    
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.lang = 'en-US';
    
    recognition.onresult = (event: any) => {
      let interimTranscript = '';
      let finalTranscript = '';
      
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalTranscript += transcript;
        } else {
          interimTranscript += transcript;
        }
      }
      
      onTranscriptUpdate(finalTranscript + interimTranscript);
    };
    
    recognition.start();
  };
  
  const processAudio = async () => {
    try {
      // Create audio blob
      const audioBlob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
      if (audioBlob.size < 1000) {
        throw new Error('Recording too short');
      }
      
      // Create form data
      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('session_id', sessionId);
      
      // Send to API
      const response = await axios.post('/api/v1/voice-order', formData, {
        headers: session?.user ? {
          Authorization: `Bearer ${session.accessToken}`,
          'Content-Type': 'multipart/form-data',
        } : {
          'Content-Type': 'multipart/form-data',
        }
      });
      
      // Handle response
      onResult(response.data);
      
    } catch (err) {
      console.error('Error processing audio:', err);
      onResult({ error: err.message || 'Failed to process audio' });
    } finally {
      onStop();
    }
  };
  
  // Render audio wave visualization
  const waveElements = Array.from({ length: 10 }).map((_, i) => {
    const height = Math.max(4, (audioLevel / 100) * 32);
    const randomFactor = Math.sin(Date.now() / 200 + i) * 0.5 + 0.5;
    const barHeight = Math.max(4, height * randomFactor);
    
    return (
      <div 
        key={i}
        className="bg-primary w-1 mx-px rounded-full"
        style={{ height: `${barHeight}px` }}
      />
    );
  });
  
  return (
    <div className="bg-gray-50 p-4 rounded-lg">
      <div className="flex justify-center items-center space-x-4">
        <div className="relative">
          <div className="h-12 w-12 rounded-full bg-red-100 animate-pulse flex items-center justify-center">
            <div className="h-6 w-6 rounded-full bg-red-500" />
          </div>
          <div className="absolute -bottom-1 -right-1">
            <div className="h-4 w-4 rounded-full bg-red-500 animate-ping" />
          </div>
        </div>
        
        <div className="text-sm">
          <div className="font-medium">Recording your voice...</div>
          <div className="text-gray-500">Speak clearly to place your order</div>
        </div>
      </div>
      
      <div className="mt-4 h-8 flex items-center justify-center space-x-1">
        {waveElements}
      </div>
      
      {error && (
        <div className="mt-2 text-sm text-red-500">{error}</div>
      )}
    </div>
  );
}
```

## Installation and Setup

1. **Install required dependencies**:

```bash
npm install next react react-dom axios @tanstack/react-query lucide-react date-fns
npm install tailwindcss postcss autoprefixer
npm install @radix-ui/react-icons
npx tailwindcss init -p
```

2. **Configure TailwindCSS**:

```js
// tailwind.config.js
module.exports = {
  content: [
    './pages/**/*.{js,ts,jsx,tsx}',
    './components/**/*.{js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
```

3. **Create API client**:

```js
// lib/api.js
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  timeout: 30000,
});

export default api;
```

## Deployment Checklist

- [ ] Configure environment variables in production
- [ ] Ensure proper CORS settings on backend
- [ ] Test with various browsers and devices
- [ ] Implement error tracking and monitoring
- [ ] Create fallback mechanisms for unsupported browsers

## Security Considerations

- Always transmit audio using HTTPS
- Implement rate limiting on all voice endpoints
- Consider implementing audio encryption for privacy
- Store user voice preferences securely
- Implement proper permission checks on all API endpoints

## Performance Optimization

- Use WebWorkers for audio processing when possible
- Implement lazy loading for components not in view
- Use efficient state management to prevent re-renders
- Optimize API responses for minimal payload size
- Implement proper caching strategies 