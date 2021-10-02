package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"strconv"

	_ "github.com/go-sql-driver/mysql"
	"github.com/joho/godotenv"
	"github.com/streadway/amqp"
)

func main() {
	godotenv.Load("../.env")

	db, err := sql.Open("mysql", fmt.Sprintf("%s:%s@tcp(%s)/%s", os.Getenv("DB_USER"), os.Getenv("DB_PASS"), os.Getenv("DB_HOST"), os.Getenv("DB_NAME")))
	if err != nil {
		fmt.Println("Failed connecting MySQL")
		panic(err)
	}
	poolSize, _ := strconv.Atoi(os.Getenv("DB_POOL"))
	db.SetMaxOpenConns(poolSize)

	conn, err := amqp.Dial("amqp://guest:guest@localhost:5672/")
	if err != nil {
		fmt.Println("Failed connecting RabbitMQ")
		panic(err)
	}

	ch, err := conn.Channel()
	if err != nil {
		fmt.Println(err)
	}
	defer ch.Close()

	if err != nil {
		fmt.Println(err)
	}

	msgs, err := ch.Consume(
		"message_queue",
		"",
		true,
		false,
		false,
		false,
		nil,
	)

	forever := make(chan bool)
	go func() {
		for d := range msgs {
			// fmt.Printf("Processing: %s\n", d.Body)
			processMessage(db)
			publishEvent(chan)
		}
	}()

	fmt.Println("Consuming")
	<-forever
}

func processMessage(db) {
    var msg map[string]interface{}
    json.Unmarshal([]byte(d.Body), &msg)
    db.Exec("START TRANSACTION")
    db.Exec("SELECT value FROM data WHERE id=?", msg["id"])
    db.Exec("UPDATE data SET value=? WHERE id=?", msg["value"], msg["id"])
    db.Exec("COMMIT")
}

func publishEvent() {

}