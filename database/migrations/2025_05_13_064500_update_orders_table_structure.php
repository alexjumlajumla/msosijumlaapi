use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateOrdersTableStructure extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Ensure existing columns match the provided structure
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('shop_id')->nullable()->change();
            $table->foreignId('currency_id')->nullable()->change();
            $table->foreignId('deliveryman')->nullable()->change();
            $table->foreignId('address_id')->nullable()->change();
            $table->foreignId('waiter_id')->nullable()->change();
            $table->unsignedBigInteger('table_id')->nullable()->change();
            $table->unsignedBigInteger('booking_id')->nullable()->change();
            $table->unsignedBigInteger('user_booking_id')->nullable()->change();
            $table->unsignedBigInteger('cart_id')->nullable()->change();

            // Add any missing columns
            $table->string('img')->nullable();
            $table->string('email')->nullable();
            $table->string('image_after_delivered')->nullable();
            $table->smallInteger('otp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Reverse changes if necessary
            $table->dropColumn(['img', 'email', 'image_after_delivered', 'otp']);
        });
    }
} 