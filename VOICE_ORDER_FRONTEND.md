# Voice Order Frontend Implementation Guide

## Overview

This document provides guidelines for implementing voice ordering functionality in Next.js applications. The voice ordering system allows users to place orders using voice commands, with AI-powered intent detection and product recommendations.

## API Endpoints

The following API endpoints are available for voice order processing:

| Endpoint | Description | Authentication |
|----------|-------------|----------------|
| `/api/voice/process` | **Primary endpoint** - Process voice recordings and return recommendations | Required |
| `/api/voice-dialogue/process` | Alternative endpoint for dialogue-based voice processing | Required |
| `/api/v1/voice-order` | REST API endpoint for voice order processing | Required |

## Response Format

All endpoints return responses in the following format:

```json
{
  "success": true,
  "transcription": "User's transcribed speech",
  "intent_data": {
    "intent": "primary intent (e.g., burger)",
    "filters": ["vegetarian", "etc"],
    "cuisine_type": "American",
    "exclusions": ["item to exclude"],
    "portion_size": "Regular",
    "spice_level": "Not specified"
  },
  "recommendations": [
    {
      "id": 123,
      "slug": "product-slug",
      "img": "image-url",
      "translation": {
        "title": "Product Title",
        "description": "Product Description"
      },
      "stocks": [
        {
          "price": 12000,
          "quantity": 200
        }
      ]
      // Additional product details...
    }
  ],
  "recommendation_text": "AI-generated recommendation text",
  "session_id": "unique-session-id"
}
```

## NextJS Implementation Example

Here's a complete Next.js component implementation for voice ordering:

```jsx
import { useRef, useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useRouter } from 'next/router';
import { useSession } from 'next-auth/react';
import { toast } from '@/components/ui/toast';

export default function VoiceOrderPage() {
  const { data: session, status } = useSession();
  const router = useRouter();
  const [isRecording, setIsRecording] = useState(false);
  const [status, setStatus] = useState('Click the mic to begin');
  const [isProcessing, setIsProcessing] = useState(false);
  const [transcription, setTranscription] = useState('');
  const [recommendation, setRecommendation] = useState('');
  const [products, setProducts] = useState([]);
  const [voiceOrderHistory, setVoiceOrderHistory] = useState([]);
  const mediaRecorderRef = useRef(null);
  const audioChunks = useRef([]);
  
  // Check if user is authenticated
  useEffect(() => {
    if (status === 'unauthenticated') {
      router.push('/login?callbackUrl=/voice-order');
    }
    
    if (status === 'authenticated') {
      // Load voice order history
      fetchVoiceOrderHistory();
    }
  }, [status, router]);
  
  const fetchVoiceOrderHistory = async () => {
    try {
      const res = await fetch('/api/voice-order/history');
      const data = await res.json();
      if (data.success) {
        setVoiceOrderHistory(data.orders);
      }
    } catch (error) {
      console.error('Failed to fetch voice order history:', error);
    }
  };

  const handleMicClick = async () => {
    if (!session) {
      router.push('/login?callbackUrl=/voice-order');
      return;
    }

    if (isRecording) {
      mediaRecorderRef.current.stop();
      setIsRecording(false);
      setStatus('Processing...');
      setIsProcessing(true);
    } else {
      try {
        // Reset states
        setTranscription('');
        setRecommendation('');
        setProducts([]);
        
        // Determine supported MIME type
        let mimeType = 'audio/webm;codecs=opus';
        if (!MediaRecorder.isTypeSupported(mimeType)) {
            mimeType = 'audio/webm';
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                mimeType = 'audio/ogg;codecs=opus';
                if (!MediaRecorder.isTypeSupported(mimeType)) {
                    mimeType = '';  // Let browser choose
                }
            }
        }
        
        const stream = await navigator.mediaDevices.getUserMedia({ 
          audio: {
            sampleRate: 16000,
            channelCount: 1,
            echoCancellation: true,
            noiseSuppression: true
          } 
        });
        
        const options = {
          audioBitsPerSecond: 16000
        };
        
        if (mimeType) {
          options.mimeType = mimeType;
        }
        
        const recorder = new MediaRecorder(stream, options);
        mediaRecorderRef.current = recorder;
        audioChunks.current = [];

        recorder.ondataavailable = e => audioChunks.current.push(e.data);
        recorder.onstop = handleAudioStop;

        recorder.start();
        setIsRecording(true);
        setStatus('Recording... Click to stop');
      } catch (err) {
        console.error(err);
        setStatus('Microphone access denied.');
        toast({
          title: "Error",
          description: "Microphone access denied. Please check your browser permissions.",
          variant: "destructive",
        });
      }
    }
  };

  const handleAudioStop = async () => {
    try {
      // Get mime type from mediaRecorder
      const mimeType = mediaRecorderRef.current.mimeType || 'audio/webm';
      const audioBlob = new Blob(audioChunks.current, { type: mimeType });
      
      console.log(`Recording complete: ${(audioBlob.size / 1024).toFixed(2)} KB, MIME type: ${mimeType}`);
      
      const formData = new FormData();
      formData.append('audio', audioBlob, `recording${mimeType.includes('webm') ? '.webm' : mimeType.includes('ogg') ? '.ogg' : '.wav'}`);
      formData.append('session_id', `web-${Date.now()}`);
      formData.append('language', 'en-US');

      // Try multiple endpoints in sequence to improve reliability
      let response = null;
      let error = null;
      
      try {
        response = await fetch('/api/voice/process', {
          method: 'POST',
          body: formData,
          credentials: 'include'
        });
        console.log('Primary endpoint succeeded');
      } catch (err) {
        console.warn('Primary endpoint failed:', err);
        error = err;
        response = null;
      }
      
      // If primary failed, try the voice-dialogue endpoint
      if (!response || !response.ok) {
        try {
          response = await fetch('/api/voice-dialogue/process', {
            method: 'POST',
            body: formData,
            credentials: 'include'
          });
          console.log('Voice-dialogue endpoint succeeded');
        } catch (err) {
          console.warn('Voice-dialogue endpoint failed:', err);
          error = err;
          response = null;
        }
      }
      
      // If both failed, try the v1 endpoint
      if (!response || !response.ok) {
        try {
          response = await fetch('/api/v1/voice-order', {
            method: 'POST',
            body: formData,
            credentials: 'include'
          });
          console.log('V1 endpoint succeeded');
        } catch (err) {
          console.warn('V1 endpoint failed:', err);
          error = err;
          response = null;
        }
      }
      
      // If all endpoints failed, show error
      if (!response || !response.ok) {
        throw new Error('All API endpoints failed: ' + (error ? error.message : 'Unknown error'));
      }

      const data = await response.json();
      if (data.success) {
        setTranscription(data.transcription);
        setRecommendation(data.intent_data?.intent || '');
        setProducts(data.recommendations || []);
        setStatus('Order processed.');

        // Quick checkout if order contains "cash" or "checkout"
        if (data.transcription.toLowerCase().includes('cash') || 
            data.transcription.toLowerCase().includes('checkout') || 
            data.transcription.toLowerCase().includes('pay')) {
          
          // Add products to cart
          for (const product of data.recommendations.slice(0, 3)) { // Limit to top 3 products
            await fetch('/api/cart/add', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ 
                product_id: product.id, 
                quantity: 1,
                shop_id: product.shop_id 
              }),
            });
          }

          if (data.transcription.toLowerCase().includes('cash')) {
            // Create cash order
            await fetch('/api/orders/create', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ payment_method: 'cash' }),
            });
            router.push('/orders/voice-success');
          } else {
            // Redirect to checkout for other payment methods
            router.push('/checkout');
          }
          return;
        }
        
        // Send feedback about successful processing
        await fetch('/api/voice-order/feedback', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ 
            voice_order_id: data.voice_order_id,
            feedback: 'positive', 
            rating: 5 
          }),
        });
      } else {
        setStatus('Processing failed. Try again or use buttons below.');
        toast({
          title: "Processing Failed",
          description: data.message || "Could not process your voice order. Please try again.",
          variant: "destructive",
        });
      }
    } catch (err) {
      console.error(err);
      setStatus('Error submitting audio.');
      toast({
        title: "Error",
        description: "Failed to process audio. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsProcessing(false);
    }
  };

  const addToCart = async (product) => {
    try {
      await fetch('/api/cart/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
          product_id: product.id, 
          quantity: 1,
          shop_id: product.shop_id 
        }),
      });
      
      toast({
        title: "Added to Cart",
        description: `${product.translation.title} added to your cart.`,
        variant: "success",
      });
    } catch (error) {
      console.error('Failed to add to cart:', error);
      toast({
        title: "Error",
        description: "Failed to add item to cart.",
        variant: "destructive",
      });
    }
  };

  if (status === 'loading') {
    return <div className="flex justify-center items-center min-h-screen">Loading...</div>;
  }

  return (
    <div className="max-w-4xl mx-auto py-10 px-4">
      <h1 className="text-3xl font-semibold mb-4">Voice Order Assistant</h1>
      <p className="text-muted-foreground mb-6">Tap the mic and say something like "I want a burger" or "vegetarian lunch".</p>

      <div className="flex justify-center mb-4">
        <Button 
          onClick={handleMicClick} 
          disabled={isProcessing}
          className={`rounded-full h-20 w-20 text-2xl ${isRecording ? 'bg-green-500' : isProcessing ? 'bg-yellow-500' : 'bg-red-500'}`}
        >
          {isRecording ? '🎙️' : isProcessing ? '⏳' : '🎤'}
        </Button>
      </div>

      <p className="text-center text-sm mb-6 text-muted-foreground">{status}</p>

      {transcription && (
        <div className="mb-4">
          <h3 className="text-lg font-medium">Transcription:</h3>
          <p className="bg-muted p-3 rounded-md mt-2 text-sm">{transcription}</p>
        </div>
      )}

      {recommendation && (
        <div className="mb-4">
          <h3 className="text-lg font-medium">Detected Intent:</h3>
          <Badge>{recommendation}</Badge>
        </div>
      )}

      {products.length > 0 && (
        <>
          <h3 className="text-lg font-medium mb-3">Recommendations:</h3>
          <div className="grid md:grid-cols-3 gap-4 mt-2">
            {products.map(product => (
              <Card key={product.id} className="backdrop-blur-sm bg-white/30 shadow-xl">
                <CardContent className="p-4">
                  <img
                    src={product.img || 'https://via.placeholder.com/150'}
                    alt={product.translation?.title || 'Product'}
                    className="rounded-md w-full h-32 object-cover"
                  />
                  <h4 className="font-semibold mt-2 text-base">{product.translation?.title}</h4>
                  <p className="text-xs text-muted-foreground mt-1 line-clamp-2">{product.translation?.description}</p>
                  <div className="flex justify-between items-center mt-2">
                    <p className="text-sm font-medium text-green-600">
                      {product.stocks?.[0]?.price ? `${(product.stocks[0].price / 100).toFixed(2)} TZS` : 'N/A'}
                    </p>
                    <Button size="sm" onClick={() => addToCart(product)}>Add to Cart</Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
          
          <div className="mt-6 flex justify-center gap-4">
            <Button variant="outline" onClick={() => router.push('/cart')}>Go to Cart</Button>
            <Button onClick={() => router.push('/checkout')}>Checkout</Button>
          </div>
        </>
      )}

      {voiceOrderHistory.length > 0 && (
        <div className="mt-10">
          <h3 className="text-lg font-medium mb-3">Voice Order History</h3>
          <div className="space-y-3">
            {voiceOrderHistory.map(order => (
              <Card key={order.id} className="bg-white/30">
                <CardContent className="p-4">
                  <div className="flex justify-between mb-2">
                    <Badge variant={order.status === 'completed' ? 'success' : 'secondary'}>
                      {order.status}
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {new Date(order.created_at).toLocaleString()}
                    </span>
                  </div>
                  <p className="text-sm font-medium">{order.transcription || 'No transcription'}</p>
                  {order.recommendations && (
                    <div className="mt-2 flex flex-wrap gap-2">
                      {order.recommendations.slice(0, 3).map(item => (
                        <Badge key={item.id} variant="outline">{item.translation?.title}</Badge>
                      ))}
                    </div>
                  )}
                  <div className="mt-2 flex justify-end">
                    <Button 
                      size="sm" 
                      variant="ghost" 
                      onClick={() => router.push(`/orders/${order.order_id}`)}
                      disabled={!order.order_id}
                    >
                      {order.order_id ? 'View Order' : 'No Order Created'}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
```

## Integration with Cart and Checkout

### Cart Integration

Voice orders can be integrated with your existing cart system:

1. After processing the voice order, add recommended products to the cart
2. Direct users to the cart for review before checkout
3. Implement quick checkout for "cash" orders

```jsx
// Add to cart example
const addToCart = async (product) => {
  await fetch('/api/cart/add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      product_id: product.id, 
      quantity: 1,
      shop_id: product.shop_id 
    }),
  });
};
```

### Checkout Integration

For voice orders, support two checkout paths:

1. **Express checkout**: When the user says "cash" or "pay with cash"
   - Automatically create an order with cash payment method
   - Redirect to order success page

2. **Regular checkout**: For all other payment methods
   - Add items to cart
   - Redirect to checkout page for payment selection

```jsx
// Cash order example
if (transcription.toLowerCase().includes('cash')) {
  await fetch('/api/orders/create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ payment_method: 'cash' }),
  });
  router.push('/orders/voice-success');
} else {
  router.push('/checkout');
}
```

## Audio Handling Best Practices

For optimal voice recognition:

1. **Sample Rate**: Use 16kHz for best compatibility with Google Speech API
2. **Audio Format**: Use `audio/webm;codecs=opus` if supported
3. **Channel Count**: Use a single channel (mono) recording
4. **Audio Processing**: Enable noise suppression and echo cancellation

```javascript
// Optimal audio settings
const stream = await navigator.mediaDevices.getUserMedia({ 
  audio: {
    sampleRate: 16000,
    channelCount: 1,
    echoCancellation: true,
    noiseSuppression: true
  } 
});

const options = {
  mimeType: 'audio/webm;codecs=opus',
  audioBitsPerSecond: 16000
};
```

## Error Handling

Implement robust error handling for:

1. Microphone access denial
2. Network connectivity issues
3. Audio processing failures
4. Authentication failures

Use a fallback mechanism to try multiple endpoints when one fails.

## Authentication

Users must be authenticated to use voice ordering. Implement:

1. Session checking before recording
2. Redirect to login if unauthenticated
3. Return to voice order page after login

## Responsive Design

The voice order interface should be responsive for both desktop and mobile:

1. Large, easily tappable microphone button
2. Clear visual feedback during recording
3. Readable transcription and recommendations
4. Touch-friendly product cards

## Accessibility

Ensure voice ordering is accessible:

1. Provide alternative text input for users unable to use voice
2. Include keyboard navigation support
3. Provide clear visual and text feedback
4. Use ARIA attributes for screen reader support

## API References

### Voice Processing

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/voice/process` | POST | Process voice recording |
| `/api/voice-order/history` | GET | Get user's voice order history |
| `/api/voice-order/feedback` | POST | Submit feedback for a voice order |

### Cart & Checkout Integration

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/cart/add` | POST | Add product to cart |
| `/api/orders/create` | POST | Create an order |
| `/api/cart` | GET | Get current cart items |

## Testing

Test the voice order system thoroughly:

1. Test with different accents and languages
2. Test with background noise
3. Test with different product requests
4. Test on different devices and browsers
5. Test authentication and authorization
6. Test integration with cart and checkout 