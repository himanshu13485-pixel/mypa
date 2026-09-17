import { beforeEach, describe, expect, it } from 'vitest'
import { MAX_ACCOUNTS, useAuthStore } from './auth'
import type { User } from '../types'

/**
 * Three seats in one browser.
 *
 * A personal account and the companies somebody works in used to mean
 * signing out and signing in again, which on a phone is a password each way.
 * What matters here is that the seats stay separate: the wrong token, or a
 * list left behind, shows one person another's work.
 */
const person = (uuid: string, name: string): User => ({ uuid, name } as User)

const reset = () => useAuthStore.setState({ token: null, user: null, accounts: [] })

describe('the accounts a browser holds', () => {
  beforeEach(reset)

  it('remembers who signed in, and makes them the one in use', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))

    const { token, user, accounts } = useAuthStore.getState()
    expect(token).toBe('tok-a')
    expect(user?.uuid).toBe('a')
    expect(accounts).toHaveLength(1)
  })

  it('signing in again as the same person is a fresh session, not a second seat', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))
    useAuthStore.getState().setAuth('tok-a2', person('a', 'Himanshu'))

    expect(useAuthStore.getState().accounts).toHaveLength(1)
    expect(useAuthStore.getState().token).toBe('tok-a2')
    // The stale token must not be left behind anywhere.
    expect(useAuthStore.getState().accounts[0].token).toBe('tok-a2')
  })

  it('holds three, and the fourth pushes out the oldest', () => {
    for (const [token, uuid] of [['t1', 'a'], ['t2', 'b'], ['t3', 'c'], ['t4', 'd']]) {
      useAuthStore.getState().setAuth(token, person(uuid, uuid.toUpperCase()))
    }

    const { accounts } = useAuthStore.getState()
    expect(accounts).toHaveLength(MAX_ACCOUNTS)
    expect(accounts.map((a) => a.uuid)).toEqual(['b', 'c', 'd'])
  })

  it('switching swaps the token, which is what every request reads', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))
    useAuthStore.getState().setAuth('tok-b', person('b', 'GrapOut CRM'))

    const moved = useAuthStore.getState().switchTo('a')

    expect(moved?.uuid).toBe('a')
    expect(useAuthStore.getState().token).toBe('tok-a')
    expect(useAuthStore.getState().user?.uuid).toBe('a')
    // Both seats are still held: switching is not signing out.
    expect(useAuthStore.getState().accounts).toHaveLength(2)
  })

  it('will not switch to somebody this browser is not holding', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))

    expect(useAuthStore.getState().switchTo('nobody')).toBeNull()
    expect(useAuthStore.getState().token).toBe('tok-a')
  })

  it('signing out of one leaves the others signed in', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))
    useAuthStore.getState().setAuth('tok-b', person('b', 'GrapOut CRM'))

    const next = useAuthStore.getState().signOutActive()

    expect(next?.uuid).toBe('a')
    expect(useAuthStore.getState().token).toBe('tok-a')
    expect(useAuthStore.getState().accounts.map((a) => a.uuid)).toEqual(['a'])
  })

  it('signing out of the last one is an ordinary sign-out', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))

    expect(useAuthStore.getState().signOutActive()).toBeNull()
    expect(useAuthStore.getState().token).toBeNull()
    expect(useAuthStore.getState().accounts).toEqual([])
  })

  it('clear takes every seat, because a 401 takes this route too', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))
    useAuthStore.getState().setAuth('tok-b', person('b', 'GrapOut CRM'))

    useAuthStore.getState().clear()

    expect(useAuthStore.getState().token).toBeNull()
    expect(useAuthStore.getState().accounts).toEqual([])
  })

  it('a renamed person is the same account, not a new one', () => {
    useAuthStore.getState().setAuth('tok-a', person('a', 'Himanshu'))
    useAuthStore.getState().setUser(person('a', 'Himanshu Sachdeva'))

    const { accounts } = useAuthStore.getState()
    expect(accounts).toHaveLength(1)
    expect(accounts[0].user.name).toBe('Himanshu Sachdeva')
  })
})
