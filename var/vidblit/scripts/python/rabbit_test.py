import puka

# declare send and receive clients, both connecting to the same server on local machine
producer = puka.Client("amqp://localhost/")
consumer = puka.Client("amqp://localhost/")

# connect sending party
send_promise = producer.connect()
producer.wait(send_promise)

# connect receiving party
receive_promise = consumer.connect()
consumer.wait(receive_promise)

# declare queue (queue must exist before it is being used - otherwise messages sent to that queue will be discarded)
send_promise = producer.queue_declare(queue='rabbit')
producer.wait(send_promise)

# send message to the queue named rabbit
send_promise = producer.basic_publish(exchange='', routing_key='rabbit', body='Droplet test!')
producer.wait(send_promise)

print "Message sent!"

# start waiting for messages, also those sent before (!), on the queue named rabbit
receive_promise = consumer.basic_consume(queue='rabbit', no_ack=True)

print "Starting receiving!"

while True:
    received_message = consumer.wait(receive_promise)
    print "GOT: %r" % (received_message['body'],)
    break