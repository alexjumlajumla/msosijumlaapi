# Voice Order System Frontend Integration Guide

## Overview

This guide will help you integrate voice ordering capabilities into your existing food ordering system. This enhanced interface will allow users to:

1. Record their voice to place food orders
2. Type text messages in a chat interface
3. Switch seamlessly between voice and text input
4. View real-time transcription feedback
5. Receive AI-powered food recommendations
6. Browse recommended products
7. Continue to checkout using your existing flow

## Integration Strategy

Instead of building a separate voice ordering system, we'll:
1. Add voice + chat components to your existing UI
2. Use your current product/shop/category API endpoints
3. Leverage your existing checkout flow
4. Maintain consistent UX across input methods

## API Endpoints

Your backend already provides these key endpoints for voice processing:

| Endpoint | Method | Description | Authentication |
|----------|--------|-------------|----------------|
| `/api/v1/voice-order` | POST | Process voice recordings for order intent | Optional |
| `/api/v1/voice-order/repeat` | POST | Repeat a previous order | Required |
| `/api/v1/voice-order/realtime-transcription` | POST | Process streaming audio | Required |
| `/api/v1/voice-order/feedback` | POST | Submit feedback on order recommendations | Required |
| `/api/v1/voice-order/history` | GET | Get user's voice order history | Required |
| `/api/v1/voice-order/test-transcribe` | POST | Test speech-to-text without AI processing | None |

For text chat processing, we'll add:

| Endpoint | Method | Description | Authentication |
|----------|--------|-------------|----------------|
| `/api/v1/ai-chat` | POST | Process text-based food orders | Optional |
| `/api/v1/ai-chat/context` | POST | Update conversation context | Required |

## Frontend Implementation (Next.js)

### 1. Project Setup

```bash
npx create-next-app voice-order-frontend
cd voice-order-frontend
npm install axios react-use-clipboard react-icons
```

### 2. Environment Configuration

Create `.env.local` file:

```
NEXT_PUBLIC_API_URL=http://your-laravel-backend-url/api/v1
```

### 3. Hybrid Voice + Chat Component

Create a `components/AIOrderAssistant.js` file:

```jsx
import { useState, useRef, useEffect } from 'react';
import axios from 'axios';
import { FaMicrophone, FaStop, FaShoppingCart, FaPaperPlane, FaTimes } from 'react-icons/fa';
import styles from '../styles/AIOrderAssistant.module.css';

export default function AIOrderAssistant({ user, token, onAddToCart, onProceedToCheckout }) {
  // Input mode state
  const [inputMode, setInputMode] = useState('text'); // 'text' or 'voice'
  
  // Voice recording states
  const [isRecording, setIsRecording] = useState(false);
  const [transcript, setTranscript] = useState('');
  const [realtimeTranscript, setRealtimeTranscript] = useState('');
  const mediaRecorderRef = useRef(null);
  const audioChunksRef = useRef([]);
  const streamRef = useRef(null);
  const recognitionRef = useRef(null);

  // Chat states
  const [messages, setMessages] = useState([
    { 
      role: 'assistant', 
      content: 'Hello! What would you like to order today? You can speak or type your order.' 
    }
  ]);
  const [inputText, setInputText] = useState('');
  
  // Shared states
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [conversationContext, setConversationContext] = useState({});
  
  // Initialize speech recognition
  useEffect(() => {
    if (typeof window !== 'undefined' && 
        ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window)) {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      recognitionRef.current = new SpeechRecognition();
      recognitionRef.current.continuous = true;
      recognitionRef.current.interimResults = true;
      
      recognitionRef.current.onresult = (event) => {
        let interimTranscript = '';
        
        for (let i = event.resultIndex; i < event.results.length; i++) {
          if (event.results[i].isFinal) {
            setRealtimeTranscript(prev => prev + event.results[i][0].transcript + ' ');
          } else {
            interimTranscript += event.results[i][0].transcript;
          }
        }
        
        const transcriptElement = document.getElementById('realtime-transcript');
        if (transcriptElement) {
          transcriptElement.innerText = realtimeTranscript + (interimTranscript ? `... ${interimTranscript}` : '');
        }
      };
    }
    
    return () => {
      if (recognitionRef.current) {
        try {
          recognitionRef.current.stop();
        } catch (e) {
          // Ignore errors when stopping
        }
      }
    };
  }, []);
  
  // Voice recording functions
  const startRecording = async () => {
    try {
      setError(null);
      setRealtimeTranscript('');
      
      // Get user media
      streamRef.current = await navigator.mediaDevices.getUserMedia({ audio: true });
      
      // Set up MediaRecorder
      mediaRecorderRef.current = new MediaRecorder(streamRef.current);
      audioChunksRef.current = [];
      
      mediaRecorderRef.current.ondataavailable = (event) => {
        audioChunksRef.current.push(event.data);
      };
      
      mediaRecorderRef.current.onstop = () => {
        processVoiceRecording();
      };
      
      // Start recording
      mediaRecorderRef.current.start();
      setIsRecording(true);
      
      // Start speech recognition for real-time feedback
      if (recognitionRef.current) {
        recognitionRef.current.start();
      }
      
      // Update input mode
      setInputMode('voice');
    } catch (err) {
      console.error('Error starting recording:', err);
      setError(`Could not start recording: ${err.message}`);
    }
  };
  
  const stopRecording = () => {
    if (mediaRecorderRef.current && isRecording) {
      mediaRecorderRef.current.stop();
      setIsRecording(false);
      
      // Stop all tracks
      if (streamRef.current) {
        streamRef.current.getTracks().forEach(track => track.stop());
      }
      
      // Stop speech recognition
      if (recognitionRef.current) {
        recognitionRef.current.stop();
      }
    }
  };
  
  const processVoiceRecording = async () => {
    try {
      setLoading(true);
      
      const audioBlob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('language', 'en-US');
      
      // If we have conversation context, add it to the request
      if (Object.keys(conversationContext).length > 0) {
        formData.append('context', JSON.stringify(conversationContext));
      }
      
      const headers = {};
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
      
      const response = await axios.post(
        `${process.env.NEXT_PUBLIC_API_URL}/voice-order`,
        formData,
        { headers }
      );
      
      handleAIResponse(response.data, realtimeTranscript);
    } catch (err) {
      console.error('Error processing recording:', err);
      setError(`Error processing your order: ${err.message}`);
      
      setMessages(prev => [
        ...prev,
        { role: 'user', content: realtimeTranscript || 'Voice recording' },
        { role: 'assistant', content: `Sorry, I couldn't process your request. ${err.message}` }
      ]);
    } finally {
      setLoading(false);
    }
  };
  
  // Text chat functions
  const handleTextSubmit = async (e) => {
    e.preventDefault();
    
    if (!inputText.trim()) return;
    
    const userMessage = inputText.trim();
    setInputText('');
    
    // Add user message to chat
    setMessages(prev => [...prev, { role: 'user', content: userMessage }]);
    
    try {
      setLoading(true);
      
      const requestData = {
        message: userMessage,
        context: conversationContext
      };
      
      const headers = {};
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
        headers['Content-Type'] = 'application/json';
      }
      
      const response = await axios.post(
        `${process.env.NEXT_PUBLIC_API_URL}/ai-chat`,
        requestData,
        { headers }
      );
      
      handleAIResponse(response.data, userMessage);
    } catch (err) {
      console.error('Error processing text message:', err);
      setError(`Error processing your request: ${err.message}`);
      
      setMessages(prev => [
        ...prev,
        { role: 'assistant', content: `Sorry, I couldn't process your request. ${err.message}` }
      ]);
    } finally {
      setLoading(false);
    }
  };
  
  // Shared response handling
  const handleAIResponse = (data, userInput) => {
    // Store the result for product display
    setResult(data);
    
    // Update conversation context
    if (data.context) {
      setConversationContext(data.context);
    }
    
    // Add AI response to chat
    setMessages(prev => [
      ...prev,
      { role: 'user', content: userInput },
      { role: 'assistant', content: data.recommendation_text || 'I found some products that might interest you.' }
    ]);
  };
  
  // Function to add product to cart (integrates with existing cart system)
  const addToCart = async (productId) => {
    try {
      if (onAddToCart && typeof onAddToCart === 'function') {
        // Use the parent component's handler if provided
        await onAddToCart(productId, 1);
        return;
      }
      
      // Default implementation
      if (!token) {
        setError('Please login to add items to your cart');
        return;
      }
      
      const response = await axios.post(
        `${process.env.NEXT_PUBLIC_API_URL}/rest/cart/insert-product`,
        { product_id: productId, quantity: 1 },
        { 
          headers: { 
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json' 
          } 
        }
      );
      
      if (response.data.success) {
        // Add a confirmation message in the chat
        setMessages(prev => [
          ...prev,
          { role: 'assistant', content: `Added to your cart!` }
        ]);
      } else {
        setError(`Failed to add to cart: ${response.data.message}`);
      }
    } catch (err) {
      console.error('Error adding to cart:', err);
      setError(`Error adding to cart: ${err.message}`);
    }
  };
  
  // Function to proceed to checkout (integrates with existing checkout)
  const proceedToCheckout = () => {
    if (onProceedToCheckout && typeof onProceedToCheckout === 'function') {
      onProceedToCheckout();
    }
  };
  
  // UI rendering
  return (
    <div className={styles.aiAssistantContainer}>
      {/* Chat Messages */}
      <div className={styles.messagesContainer}>
        {messages.map((message, index) => (
          <div 
            key={index} 
            className={`${styles.message} ${message.role === 'user' ? styles.userMessage : styles.assistantMessage}`}
          >
            {message.content}
          </div>
        ))}
        
        {loading && (
          <div className={styles.typingIndicator}>
            <div className={styles.dot}></div>
            <div className={styles.dot}></div>
            <div className={styles.dot}></div>
          </div>
        )}
      </div>
      
      {/* Input Controls */}
      <div className={styles.controlsSection}>
        {isRecording ? (
          // Recording active UI
          <div className={styles.recordingActive}>
            <div className={styles.recordingIndicator}>
              <div className={styles.pulse}></div>
              Recording...
            </div>
            
            <div className={styles.realtimeTranscript} id="realtime-transcript">
              {realtimeTranscript}
            </div>
            
            <button 
              className={styles.stopButton}
              onClick={stopRecording}
            >
              <FaStop /> Stop Recording
            </button>
          </div>
        ) : (
          // Input selection UI
          <div className={styles.inputControls}>
            {inputMode === 'text' ? (
              <form onSubmit={handleTextSubmit} className={styles.textInputForm}>
                <input
                  type="text"
                  value={inputText}
                  onChange={(e) => setInputText(e.target.value)}
                  placeholder="Type your order..."
                  className={styles.textInput}
                  disabled={loading}
                />
                <button 
                  type="submit" 
                  className={styles.sendButton}
                  disabled={loading || !inputText.trim()}
                >
                  <FaPaperPlane />
                </button>
                <button
                  type="button"
                  onClick={startRecording}
                  className={styles.voiceInputButton}
                  disabled={loading}
                >
                  <FaMicrophone />
                </button>
              </form>
            ) : (
              <div className={styles.voiceInputForm}>
                <button
                  onClick={startRecording}
                  className={styles.recordButton}
                  disabled={loading}
                >
                  <FaMicrophone /> Start Recording
                </button>
                <button
                  onClick={() => setInputMode('text')}
                  className={styles.switchToTextButton}
                  disabled={loading}
                >
                  <FaTimes /> Switch to Text
                </button>
              </div>
            )}
          </div>
        )}
      </div>
      
      {/* Error Display */}
      {error && (
        <div className={styles.errorMessage}>
          {error}
          <button 
            onClick={() => setError(null)} 
            className={styles.dismissButton}
          >
            <FaTimes />
          </button>
        </div>
      )}
      
      {/* Product Recommendations */}
      {result && result.recommendations && result.recommendations.length > 0 && (
        <div className={styles.recommendationsSection}>
          <h3>Recommended Products</h3>
          <div className={styles.productsGrid}>
            {result.recommendations.map(product => (
              <div key={product.id} className={styles.productCard}>
                <img 
                  src={product.img ? `/storage/${product.img}` : '/placeholder-food.jpg'} 
                  alt={product.translation?.title || product.title}
                  className={styles.productImage}
                />
                <div className={styles.productInfo}>
                  <h4>{product.translation?.title || product.title}</h4>
                  <p className={styles.productPrice}>
                    ${parseFloat(product.stocks?.[0]?.price || product.price).toFixed(2)}
                  </p>
                  <p className={styles.productDescription}>
                    {product.translation?.description || product.description || ''}
                  </p>
                  <button 
                    className={styles.addToCartButton}
                    onClick={() => addToCart(product.id)}
                  >
                    <FaShoppingCart /> Add to Cart
                  </button>
                </div>
              </div>
            ))}
          </div>
          
          {/* Checkout Button (only show if products have been recommended) */}
          <div className={styles.checkoutButtonContainer}>
            <button 
              className={styles.proceedToCheckoutButton}
              onClick={proceedToCheckout}
            >
              Proceed to Checkout
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
```

### 4. Styling

Create `styles/AIOrderAssistant.module.css`:

```css
.aiAssistantContainer {
  display: flex;
  flex-direction: column;
  max-width: 1000px;
  margin: 0 auto;
  height: 100%;
  min-height: 500px;
  background-color: #fff;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

/* Messages Container */
.messagesContainer {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background-color: #f9f9f9;
}

.message {
  padding: 12px 16px;
  border-radius: 10px;
  max-width: 80%;
  word-break: break-word;
}

.userMessage {
  align-self: flex-end;
  background-color: #2196F3;
  color: white;
  border-bottom-right-radius: 2px;
}

.assistantMessage {
  align-self: flex-start;
  background-color: #e9e9e9;
  color: #333;
  border-bottom-left-radius: 2px;
}

.typingIndicator {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 12px 16px;
  border-radius: 10px;
  background-color: #e9e9e9;
  width: fit-content;
  align-self: flex-start;
}

.dot {
  width: 8px;
  height: 8px;
  background-color: #666;
  border-radius: 50%;
  animation: bounce 1.5s infinite;
}

.dot:nth-child(2) {
  animation-delay: 0.2s;
}

.dot:nth-child(3) {
  animation-delay: 0.4s;
}

@keyframes bounce {
  0%, 60%, 100% {
    transform: translateY(0);
  }
  30% {
    transform: translateY(-5px);
  }
}

/* Controls Section */
.controlsSection {
  padding: 15px;
  border-top: 1px solid #e0e0e0;
  background-color: #fff;
}

.inputControls {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.textInputForm {
  display: flex;
  gap: 8px;
}

.textInput {
  flex: 1;
  padding: 12px 16px;
  border: 1px solid #e0e0e0;
  border-radius: 24px;
  font-size: 16px;
  outline: none;
}

.textInput:focus {
  border-color: #2196F3;
}

.sendButton, .voiceInputButton {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  cursor: pointer;
}

.sendButton {
  background-color: #2196F3;
  color: white;
}

.voiceInputButton {
  background-color: #4CAF50;
  color: white;
}

.sendButton:hover, .voiceInputButton:hover {
  opacity: 0.9;
}

.sendButton:disabled, .voiceInputButton:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.voiceInputForm {
  display: flex;
  gap: 10px;
  justify-content: center;
}

.recordButton, .stopButton, .switchToTextButton {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 24px;
  border: none;
  border-radius: 24px;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.recordButton, .stopButton {
  background-color: #4CAF50;
  color: white;
}

.stopButton {
  background-color: #f44336;
}

.switchToTextButton {
  background-color: #e0e0e0;
  color: #333;
}

.recordingActive {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 15px;
}

.recordingIndicator {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #f44336;
  font-weight: 500;
}

.pulse {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background-color: #f44336;
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.7);
  }
  
  70% {
    transform: scale(1);
    box-shadow: 0 0 0 10px rgba(244, 67, 54, 0);
  }
  
  100% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(244, 67, 54, 0);
  }
}

.realtimeTranscript {
  background-color: #f5f5f5;
  border-left: 4px solid #2196F3;
  padding: 15px;
  margin-top: 15px;
  border-radius: 4px;
  text-align: left;
  min-height: 50px;
  width: 100%;
  color: #333;
}

/* Error Message */
.errorMessage {
  background-color: #ffebee;
  color: #c62828;
  padding: 15px;
  margin: 10px 15px;
  border-radius: 4px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.dismissButton {
  background: none;
  border: none;
  color: #c62828;
  cursor: pointer;
  font-size: 18px;
  display: flex;
  align-items: center;
}

/* Recommendations Section */
.recommendationsSection {
  padding: 20px;
  border-top: 1px solid #e0e0e0;
}

.recommendationsSection h3 {
  margin-top: 0;
  margin-bottom: 15px;
  font-size: 18px;
  color: #333;
}

.productsGrid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 15px;
}

.productCard {
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}

.productCard:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}

.productImage {
  width: 100%;
  height: 140px;
  object-fit: cover;
}

.productInfo {
  padding: 12px;
}

.productInfo h4 {
  margin-top: 0;
  margin-bottom: 8px;
  font-size: 16px;
  color: #333;
}

.productPrice {
  font-weight: 600;
  color: #4CAF50;
  margin-bottom: 8px;
}

.productDescription {
  font-size: 14px;
  color: #666;
  margin-bottom: 12px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.addToCartButton {
  width: 100%;
  padding: 8px;
  background-color: #2196F3;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
}

.addToCartButton:hover {
  background-color: #1976D2;
}

.checkoutButtonContainer {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}

.proceedToCheckoutButton {
  padding: 12px 24px;
  background-color: #4CAF50;
  color: white;
  border: none;
  border-radius: 4px;
  font-weight: 600;
  cursor: pointer;
  font-size: 16px;
}

.proceedToCheckoutButton:hover {
  background-color: #388E3C;
}

/* Responsive Design */
@media (max-width: 768px) {
  .productsGrid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  }
  
  .message {
    max-width: 90%;
  }
}

@media (max-width: 480px) {
  .productsGrid {
    grid-template-columns: 1fr;
  }
  
  .voiceInputForm {
    flex-direction: column;
  }
}
```

### 5. Integration with Existing Checkout

Create `pages/VoiceOrderPage.js` that integrates with your existing cart and checkout system:

```jsx
import { useState, useEffect } from 'react';
import { useRouter } from 'next/router';
import Head from 'next/head';
import AIOrderAssistant from '../components/AIOrderAssistant';
import { useAuth } from '../context/AuthContext'; // Your existing auth context
import { useCart } from '../context/CartContext'; // Your existing cart context
import styles from '../styles/VoiceOrderPage.module.css';

export default function VoiceOrderPage() {
  const router = useRouter();
  const { user, token, isAuthenticated } = useAuth(); // Your existing auth hook
  const { addToCart, cartItems, cartTotal } = useCart(); // Your existing cart hook
  
  const handleAddToCart = async (productId, quantity = 1) => {
    // Use your existing addToCart function
    await addToCart(productId, quantity);
  };
  
  const handleProceedToCheckout = () => {
    // Navigate to your existing checkout page
    router.push('/checkout');
  };
  
  return (
    <div className={styles.container}>
      <Head>
        <title>Voice Food Ordering</title>
        <meta name="description" content="Order food with your voice or text" />
        <link rel="icon" href="/favicon.ico" />
      </Head>
      
      <main className={styles.main}>
        <div className={styles.header}>
          <h1 className={styles.title}>Voice & Chat Food Ordering</h1>
          
          {/* Cart Summary - Reusing your existing cart component */}
          {cartItems.length > 0 && (
            <div className={styles.cartSummary}>
              <span>{cartItems.length} items</span>
              <span>${cartTotal.toFixed(2)}</span>
              <button 
                onClick={() => router.push('/checkout')}
                className={styles.viewCartButton}
              >
                View Cart
              </button>
            </div>
          )}
        </div>
        
        {/* Main Content with AI Assistant */}
        <div className={styles.contentContainer}>
          {isAuthenticated ? (
            <AIOrderAssistant 
              user={user}
              token={token}
              onAddToCart={handleAddToCart}
              onProceedToCheckout={handleProceedToCheckout}
            />
          ) : (
            <div className={styles.loginPrompt}>
              <p>Please login to use the voice and chat ordering system</p>
              <button 
                onClick={() => router.push('/login?redirect=/voice-order')}
                className={styles.loginButton}
              >
                Login
              </button>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
```

### 6. Backend Requirements

To support the chat interface, add the following controller methods to your Laravel backend:

```php
// app/Http/Controllers/AIChatController.php

public function processTextOrder(Request $request)
{
    $request->validate([
        'message' => 'required|string',
        'context' => 'nullable|array',
    ]);
    
    // Get the authenticated user if available
    $user = Auth::user();
    
    // Process text message using the same AIOrderService
    $aiService = new AIOrderService();
    $orderData = $aiService->processOrderIntent($request->message, $user);
    
    // Generate recommendations based on intent
    $recommendedProducts = $this->getRecommendedProducts($orderData);
    
    // Generate recommendation text
    $recommendationText = $aiService->generateRecommendation($orderData);
    
    // Build and return response
    return response()->json([
        'success' => true,
        'intent_data' => $orderData,
        'recommendation_text' => $recommendationText,
        'recommendations' => $recommendedProducts,
        'context' => [
            'last_intent' => $orderData['intent'] ?? null,
            'filters' => $orderData['filters'] ?? [],
            // Include other relevant context for conversation continuity
        ],
    ]);
}
```

## Integration with Existing Product APIs

To ensure consistent product display, modify the recommendation component to use your existing product API endpoints:

```jsx
// Add this to the AIOrderAssistant component

// Load products from your existing API when intent is determined
useEffect(() => {
  if (result?.intent_data?.intent) {
    loadProductsByIntent(result.intent_data.intent);
  }
}, [result]);

const loadProductsByIntent = async (intent) => {
  try {
    // Use your existing product search API
    const response = await axios.get(
      `${process.env.NEXT_PUBLIC_API_URL}/rest/products/search?q=${intent}`,
      { headers: token ? { 'Authorization': `Bearer ${token}` } : {} }
    );
    
    // Update recommendations with consistent product data
    if (response.data.data) {
      setResult(prev => ({
        ...prev,
        recommendations: response.data.data
      }));
    }
  } catch (err) {
    console.error('Error loading products:', err);
  }
};
```

## Mobile Optimization

Ensure the hybrid interface works well on mobile:

1. Add media queries for different screen sizes
2. Use responsive flexbox/grid layouts
3. Implement touch-friendly controls (larger hit areas)
4. Handle iOS audio permission requirements

## Deployment and Integration Steps

1. Add the new components to your existing application
2. Update your navigation menu to include the voice/chat ordering option
3. Ensure your cart and checkout systems recognize products added via voice/chat
4. Add analytics to track usage patterns between traditional vs. voice/chat ordering

## Testing Checklist

In addition to the standard testing:

1. ✅ Test switching between voice and text input
2. ✅ Verify conversation flow and context retention
3. ✅ Confirm products added through voice/chat appear in the main cart
4. ✅ Ensure checkout flow works with voice/chat initiated orders
5. ✅ Test on various devices and screen sizes

This enhanced approach gives you the best of both worlds - the flexibility of voice and text input combined with the reliability of your existing product catalog and checkout system. 