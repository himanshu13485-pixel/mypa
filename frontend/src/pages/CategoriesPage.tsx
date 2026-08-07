import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { categories as categoriesApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { Button, Card, EmptyState, ErrorNote, Input, Label, Modal, Spinner } from '../components/ui'
import type { Category } from '../types'

export default function CategoriesPage() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['categories'], queryFn: () => categoriesApi.list() })
  const [editing, setEditing] = useState<Category | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ name: '', color: '#406cf0', description: '' })
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['categories'] })

  const saveMutation = useMutation({
    mutationFn: () =>
      editing
        ? categoriesApi.update(editing.uuid, form)
        : categoriesApi.create(form),
    onSuccess: () => {
      invalidate()
      close()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const deleteMutation = useMutation({
    mutationFn: (uuid: string) => categoriesApi.remove(uuid),
    onSuccess: invalidate,
  })

  const open = (category?: Category) => {
    setEditing(category ?? null)
    setForm(
      category
        ? { name: category.name, color: category.color ?? '#406cf0', description: category.description ?? '' }
        : { name: '', color: '#406cf0', description: '' },
    )
    setError(null)
    setShowForm(true)
  }

  const close = () => {
    setShowForm(false)
    setEditing(null)
  }

  const system = data?.filter((c) => c.is_system) ?? []
  const custom = data?.filter((c) => !c.is_system) ?? []

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold tracking-tight">Categories</h1>
        <Button onClick={() => open()}>
          <Plus className="size-4" /> New category
        </Button>
      </div>

      {isLoading ? (
        <Spinner />
      ) : (
        <>
          <section>
            <h2 className="mb-2 text-sm font-semibold text-slate-500">My categories</h2>
            {custom.length === 0 ? (
              <Card>
                <EmptyState title="No custom categories yet" hint="Create unlimited categories of your own." />
              </Card>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {custom.map((c) => (
                  <Card key={c.uuid} className="flex items-center gap-3">
                    <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: c.color ?? '#94a3b8' }} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{c.name}</p>
                      <p className="text-xs text-slate-400">{c.tasks_count ?? 0} tasks</p>
                    </div>
                    <button className="rounded p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" onClick={() => open(c)}>
                      <Pencil className="size-4" />
                    </button>
                    <button
                      className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950"
                      onClick={() => {
                        if (confirm(`Delete category "${c.name}"? Tasks keep existing without it.`)) {
                          deleteMutation.mutate(c.uuid)
                        }
                      }}
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </Card>
                ))}
              </div>
            )}
          </section>

          <section>
            <h2 className="mb-2 text-sm font-semibold text-slate-500">Default categories</h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {system.map((c) => (
                <Card key={c.uuid} className="flex items-center gap-3">
                  <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: c.color ?? '#94a3b8' }} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{c.name}</p>
                    <p className="text-xs text-slate-400">{c.tasks_count ?? 0} tasks</p>
                  </div>
                </Card>
              ))}
            </div>
          </section>
        </>
      )}

      {showForm && (
        <Modal title={editing ? 'Edit category' : 'New category'} onClose={close}>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              saveMutation.mutate()
            }}
            className="space-y-4"
          >
            <ErrorNote message={error} />
            <div>
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required autoFocus />
            </div>
            <div>
              <Label>Color</Label>
              <input
                type="color"
                value={form.color}
                onChange={(e) => setForm({ ...form, color: e.target.value })}
                className="h-9 w-16 cursor-pointer rounded border border-slate-300 dark:border-slate-700"
              />
            </div>
            <div>
              <Label>Description</Label>
              <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={close}>
                Cancel
              </Button>
              <Button type="submit" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
