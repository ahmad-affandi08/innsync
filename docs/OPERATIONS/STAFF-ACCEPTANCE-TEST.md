# Staff acceptance test (sheet to run with two staff)

The system is not "done" until real staff have done these on their own phone and the hotel's own computer. Two people, a few hours: one from the front desk, one from the back of house. Write what confused them; a confusing screen is a defect to fix, not something to work around. Record the date, the device (model, browser) and pass/fail for each line.

1. Sign in on the phone; if you forget the password, use **Forgot your password** and sign in with the new one.
2. Front desk: create a reservation for tomorrow, check the guest in to a room, post one charge, take a payment, open the bill and print it (or save as PDF).
3. Front desk: move the guest to another room, then check out and confirm the room shows as dirty.
4. Housekeeping: on the phone, find the dirty room, complete its checklist, mark it clean.
5. Back of house: record one clock-in and one clock-out from the phone, with the selfie and location. A manager opens the Attendance page and sees both.
6. F&B: open a table, add two items, send to the kitchen, settle the bill, print the receipt.
7. Purchasing: record a goods receipt against an order and see the stock rise.
8. Approvals: make a request that needs approval (for example a refund); a second person approves it; confirm the first person cannot approve their own.
9. Turn off the phone's data for two minutes, record something, turn data on, confirm it arrives once and not twice.
10. Face matching (after HR registered two testers): in Flag mode a tester clocks in with their own face and then a colleague tries with the same phone; check the colleague's clock-in is marked *Face did not match*. Switch to Require: the colleague is refused, the tester is not. Try in a dim room and with glasses on; note how often the right person is refused.
11. Ask each tester: which screen took longest to understand, and which step felt like extra work compared with paper.

Not yet proven by anyone: iPhone Safari, MariaDB on shared hosting, a bad mobile signal in the field. These lines are where those get tested.
